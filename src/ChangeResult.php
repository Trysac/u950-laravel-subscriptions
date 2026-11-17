<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Outcome of changing a subscription's plan.
 */
final readonly class ChangeResult
{
    /**
     * Creates a new ChangeResult.
     *
     * @param Subscription $subscription updated subscription state
     * @param ?Invoice $invoice proration invoice, or null when unprorated
     * @param ?ProrationResult $proration calculated proration figures, or null when unprorated
     * @param ?PaymentResult $paymentResult gateway result if a positive total was charged
     */
    public function __construct(
        public Subscription $subscription,
        public ?Invoice $invoice = null,
        public ?ProrationResult $proration = null,
        public ?PaymentResult $paymentResult = null,
    ) {}
}
