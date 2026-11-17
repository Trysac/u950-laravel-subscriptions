<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Outcome of a subscription renewal or invoice payment retry.
 */
final readonly class RenewalResult
{
    /**
     * Creates a new RenewalResult.
     *
     * @param Subscription $subscription updated subscription state
     * @param ?Invoice $invoice generated or updated invoice, or null if no-op
     * @param ?PaymentResult $paymentResult gateway outcome, or null if no gateway charge occurred
     */
    public function __construct(
        public Subscription $subscription,
        public ?Invoice $invoice = null,
        public ?PaymentResult $paymentResult = null,
    ) {}
}
