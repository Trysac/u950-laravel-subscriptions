<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Core state machine and billing service for subscriptions.
 */
final class SubscriptionService
{
    private int $nextSubscriptionSeq = 1;

    /**
     * Creates a new SubscriptionService.
     *
     * @param ClockInterface $clock time source for renewals, proration, and cancellations
     * @param PaymentGateway $gateway payment gateway for charging invoices
     * @param InvoiceNumberer $numberer sequential invoice number generator
     * @param ?\Closure(array{type: string, subscriptionId: string, invoice: ?string}): void $onEvent optional observer receiving event array payloads
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly PaymentGateway $gateway,
        private readonly InvoiceNumberer $numberer,
        private readonly ?\Closure $onEvent = null,
    ) {}

    /**
     * Starts a new subscription for the given plan at the given start time.
     *
     * @param Plan $plan billing plan to subscribe to
     * @param \DateTimeImmutable $start subscription start instant
     */
    public function subscribe(Plan $plan, \DateTimeImmutable $start): Subscription
    {
        $utc = new \DateTimeZone('UTC');
        $startUtc = $start->setTimezone($utc);
        $subId = sprintf('SUB-%06d', $this->nextSubscriptionSeq++);

        if ($plan->trialDays > 0) {
            $trialEnd = $startUtc->modify(sprintf('+%d days', $plan->trialDays));

            return new Subscription(
                id: $subId,
                plan: $plan,
                status: 'trialing',
                anchor: $trialEnd,
                currentPeriodStart: $startUtc,
                currentPeriodEnd: $trialEnd,
                trialEnd: $trialEnd,
                cancelAtPeriodEnd: false,
                failedAttempts: 0,
                creditBalance: 0,
            );
        }

        $periodEnd = PeriodCalculator::nextPeriodEnd($startUtc, $startUtc, $plan);
        $subscription = new Subscription(
            id: $subId,
            plan: $plan,
            status: 'active',
            anchor: $startUtc,
            currentPeriodStart: $startUtc,
            currentPeriodEnd: $periodEnd,
            trialEnd: null,
            cancelAtPeriodEnd: false,
            failedAttempts: 0,
            creditBalance: 0,
        );

        [$invoice, $newCredit] = $this->buildInvoice(
            currency: $plan->currency,
            baseLines: [new InvoiceLine(sprintf('Subscription to %s', $plan->id), $plan->priceMinor)],
            creditBalance: 0,
        );
        $this->emit('invoice_created', $subscription->id, $invoice->number);

        [$updatedSubscription] = $this->settleInvoice($subscription, $invoice, $newCredit);

        return $updatedSubscription;
    }

    /**
     * Renews a subscription if the clock has reached or passed currentPeriodEnd.
     *
     * @param Subscription $s current subscription
     *
     * @throws SubscriptionException when attempting to renew a canceled subscription
     */
    public function renew(Subscription $s): RenewalResult
    {
        if ('canceled' === $s->status) {
            throw new SubscriptionException('invalid_state', 'Cannot renew a canceled subscription.');
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        if ($now->getTimestamp() < $s->currentPeriodEnd->getTimestamp()) {
            return new RenewalResult($s, null, null);
        }

        if ($s->cancelAtPeriodEnd) {
            $canceled = new Subscription(
                id: $s->id,
                plan: $s->plan,
                status: 'canceled',
                anchor: $s->anchor,
                currentPeriodStart: $s->currentPeriodStart,
                currentPeriodEnd: $s->currentPeriodEnd,
                trialEnd: $s->trialEnd,
                cancelAtPeriodEnd: false,
                failedAttempts: $s->failedAttempts,
                creditBalance: $s->creditBalance,
            );
            $this->emit('status_changed', $canceled->id, null);

            return new RenewalResult($canceled, null, null);
        }

        $newPeriodStart = $s->currentPeriodEnd;
        $newPeriodEnd = PeriodCalculator::nextPeriodEnd($s->anchor, $newPeriodStart, $s->plan);

        $advanced = new Subscription(
            id: $s->id,
            plan: $s->plan,
            status: 'trialing' === $s->status ? 'active' : $s->status,
            anchor: $s->anchor,
            currentPeriodStart: $newPeriodStart,
            currentPeriodEnd: $newPeriodEnd,
            trialEnd: $s->trialEnd,
            cancelAtPeriodEnd: $s->cancelAtPeriodEnd,
            failedAttempts: $s->failedAttempts,
            creditBalance: $s->creditBalance,
        );

        if ('trialing' === $s->status) {
            $this->emit('status_changed', $advanced->id, null);
        }

        [$invoice, $remainingCredit] = $this->buildInvoice(
            currency: $s->plan->currency,
            baseLines: [new InvoiceLine(sprintf('Renewal for %s', $s->plan->id), $s->plan->priceMinor)],
            creditBalance: $s->creditBalance,
        );
        $this->emit('invoice_created', $advanced->id, $invoice->number);

        [$settledSub, $settledInvoice, $paymentResult] = $this->settleInvoice($advanced, $invoice, $remainingCredit);

        return new RenewalResult($settledSub, $settledInvoice, $paymentResult);
    }

    /**
     * Switches a subscription to a new plan, optionally prorating the remainder of the current period.
     *
     * @param Subscription $s current subscription
     * @param Plan $new target billing plan
     * @param bool $prorate whether to calculate and invoice proration adjustments
     *
     * @throws SubscriptionException when the subscription is canceled, currencies differ, or intervals mismatch during proration
     */
    public function changePlan(Subscription $s, Plan $new, bool $prorate = true): ChangeResult
    {
        if ('canceled' === $s->status) {
            throw new SubscriptionException('invalid_state', 'Cannot change plan on a canceled subscription.');
        }

        if ($s->plan->currency !== $new->currency) {
            throw new SubscriptionException('currency_mismatch', 'Cannot change plan to a different currency.');
        }

        if (!$prorate || 'trialing' === $s->status) {
            $updated = new Subscription(
                id: $s->id,
                plan: $new,
                status: $s->status,
                anchor: $s->anchor,
                currentPeriodStart: $s->currentPeriodStart,
                currentPeriodEnd: $s->currentPeriodEnd,
                trialEnd: $s->trialEnd,
                cancelAtPeriodEnd: $s->cancelAtPeriodEnd,
                failedAttempts: $s->failedAttempts,
                creditBalance: $s->creditBalance,
            );

            return new ChangeResult($updated, null, null, null);
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $proration = Proration::calculate(
            oldMinor: $s->plan->priceMinor,
            newMinor: $new->priceMinor,
            start: $s->currentPeriodStart,
            end: $s->currentPeriodEnd,
            at: $now,
        );

        $baseLines = [
            new InvoiceLine(sprintf('Unused time on %s', $s->plan->id), -$proration->credit),
            new InvoiceLine(sprintf('Remaining time on %s', $new->id), $proration->charge),
        ];

        [$invoice, $remainingCredit] = $this->buildInvoice(
            currency: $new->currency,
            baseLines: $baseLines,
            creditBalance: $s->creditBalance,
        );
        $this->emit('invoice_created', $s->id, $invoice->number);

        $withNewPlan = new Subscription(
            id: $s->id,
            plan: $new,
            status: $s->status,
            anchor: $s->anchor,
            currentPeriodStart: $s->currentPeriodStart,
            currentPeriodEnd: $s->currentPeriodEnd,
            trialEnd: $s->trialEnd,
            cancelAtPeriodEnd: $s->cancelAtPeriodEnd,
            failedAttempts: $s->failedAttempts,
            creditBalance: $remainingCredit,
        );

        [$settledSub, $settledInvoice, $paymentResult] = $this->settleInvoice($withNewPlan, $invoice, $remainingCredit);

        return new ChangeResult($settledSub, $settledInvoice, $proration, $paymentResult);
    }

    /**
     * Cancels a subscription immediately or marks it to cancel at the end of the current billing period.
     *
     * @param Subscription $s subscription to cancel
     * @param bool $atPeriodEnd true to cancel at currentPeriodEnd, false to cancel immediately
     *
     * @throws SubscriptionException when the subscription is already canceled
     */
    public function cancel(Subscription $s, bool $atPeriodEnd): Subscription
    {
        if ('canceled' === $s->status) {
            throw new SubscriptionException('invalid_state', 'Subscription is already canceled.');
        }

        if ($atPeriodEnd) {
            return new Subscription(
                id: $s->id,
                plan: $s->plan,
                status: $s->status,
                anchor: $s->anchor,
                currentPeriodStart: $s->currentPeriodStart,
                currentPeriodEnd: $s->currentPeriodEnd,
                trialEnd: $s->trialEnd,
                cancelAtPeriodEnd: true,
                failedAttempts: $s->failedAttempts,
                creditBalance: $s->creditBalance,
            );
        }

        $canceled = new Subscription(
            id: $s->id,
            plan: $s->plan,
            status: 'canceled',
            anchor: $s->anchor,
            currentPeriodStart: $s->currentPeriodStart,
            currentPeriodEnd: $s->currentPeriodEnd,
            trialEnd: $s->trialEnd,
            cancelAtPeriodEnd: false,
            failedAttempts: $s->failedAttempts,
            creditBalance: $s->creditBalance,
        );
        $this->emit('status_changed', $canceled->id, null);

        return $canceled;
    }

    /**
     * Retries payment for an open invoice on a subscription.
     *
     * @param Subscription $s current subscription
     * @param Invoice $i open invoice to retry charging
     *
     * @throws SubscriptionException when the subscription is canceled or the invoice is not open
     */
    public function retryPayment(Subscription $s, Invoice $i): RenewalResult
    {
        if ('canceled' === $s->status) {
            throw new SubscriptionException('invalid_state', 'Cannot retry payment on a canceled subscription.');
        }

        if ('open' !== $i->status) {
            throw new SubscriptionException('invalid_state', 'Only open invoices can be retried.');
        }

        [$settledSub, $settledInvoice, $paymentResult] = $this->settleInvoice($s, $i, $s->creditBalance);

        return new RenewalResult($settledSub, $settledInvoice, $paymentResult);
    }

    /**
     * Builds an invoice applying available creditBalance or absorbing a negative net into creditBalance.
     *
     * @param string $currency 3-letter currency code
     * @param list<InvoiceLine> $baseLines initial lines before credit adjustment
     * @param int $creditBalance current customer credit balance
     *
     * @return array{Invoice, int}
     */
    private function buildInvoice(string $currency, array $baseLines, int $creditBalance): array
    {
        $lines = $baseLines;
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line->amountMinor;
        }

        $newCreditBalance = $creditBalance;

        if ($subtotal > 0 && $newCreditBalance > 0) {
            $applied = min($subtotal, $newCreditBalance);
            $lines[] = new InvoiceLine('Applied credit balance', -$applied);
            $subtotal -= $applied;
            $newCreditBalance -= $applied;
        } elseif ($subtotal < 0) {
            $creditAdded = -$subtotal;
            $lines[] = new InvoiceLine('Added to credit balance', $creditAdded);
            $subtotal = 0;
            $newCreditBalance += $creditAdded;
        }

        $total = max(0, $subtotal);
        $invoice = new Invoice(
            number: $this->numberer->next(),
            currency: $currency,
            lines: $lines,
            total: $total,
            status: 0 === $total ? 'paid' : 'open',
        );

        return [$invoice, $newCreditBalance];
    }

    /**
     * Charges an invoice if its total is positive and updates the subscription status and failure counter.
     *
     * @return array{Subscription, Invoice, ?PaymentResult}
     */
    private function settleInvoice(Subscription $s, Invoice $invoice, int $creditBalance): array
    {
        if (0 === $invoice->total) {
            $paidInvoice = $invoice->withStatus('paid');
            $updated = new Subscription(
                id: $s->id,
                plan: $s->plan,
                status: $s->status,
                anchor: $s->anchor,
                currentPeriodStart: $s->currentPeriodStart,
                currentPeriodEnd: $s->currentPeriodEnd,
                trialEnd: $s->trialEnd,
                cancelAtPeriodEnd: $s->cancelAtPeriodEnd,
                failedAttempts: $s->failedAttempts,
                creditBalance: $creditBalance,
            );

            return [$updated, $paidInvoice, null];
        }

        $result = $this->gateway->charge($invoice);
        if ($result->approved) {
            $paidInvoice = $invoice->withStatus('paid');
            $newStatus = 'past_due' === $s->status ? 'active' : $s->status;
            $statusChanged = $newStatus !== $s->status;

            $updated = new Subscription(
                id: $s->id,
                plan: $s->plan,
                status: $newStatus,
                anchor: $s->anchor,
                currentPeriodStart: $s->currentPeriodStart,
                currentPeriodEnd: $s->currentPeriodEnd,
                trialEnd: $s->trialEnd,
                cancelAtPeriodEnd: $s->cancelAtPeriodEnd,
                failedAttempts: 0,
                creditBalance: $creditBalance,
            );

            if ($statusChanged) {
                $this->emit('status_changed', $updated->id, $paidInvoice->number);
            }

            return [$updated, $paidInvoice, $result];
        }

        $this->emit('payment_failed', $s->id, $invoice->number);

        $failures = $s->failedAttempts + 1;
        $newStatus = $failures >= 3 ? 'canceled' : 'past_due';
        $statusChanged = $newStatus !== $s->status;

        $updated = new Subscription(
            id: $s->id,
            plan: $s->plan,
            status: $newStatus,
            anchor: $s->anchor,
            currentPeriodStart: $s->currentPeriodStart,
            currentPeriodEnd: $s->currentPeriodEnd,
            trialEnd: $s->trialEnd,
            cancelAtPeriodEnd: 'canceled' === $newStatus ? false : $s->cancelAtPeriodEnd,
            failedAttempts: $failures,
            creditBalance: $creditBalance,
        );

        if ($statusChanged) {
            $this->emit('status_changed', $updated->id, $invoice->number);
        }

        return [$updated, $invoice, $result];
    }

    /**
     * Emits a domain event to the configured callback without customer PII.
     */
    private function emit(string $type, string $subscriptionId, ?string $invoiceNumber): void
    {
        if (null === $this->onEvent) {
            return;
        }

        ($this->onEvent)([
            'type' => $type,
            'subscriptionId' => $subscriptionId,
            'invoice' => $invoiceNumber,
        ]);
    }
}
