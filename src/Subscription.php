<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Immutable snapshot of a customer subscription.
 */
final readonly class Subscription
{
    /**
     * Creates a new Subscription record.
     *
     * @param string $id unique subscription identifier
     * @param Plan $plan currently subscribed billing plan
     * @param string $status one of trialing, active, past_due, canceled
     * @param \DateTimeImmutable $anchor UTC billing cycle anchor timestamp
     * @param \DateTimeImmutable $currentPeriodStart UTC start of the current period
     * @param \DateTimeImmutable $currentPeriodEnd UTC end of the current period
     * @param ?\DateTimeImmutable $trialEnd UTC end of trial period if applicable
     * @param bool $cancelAtPeriodEnd whether the subscription will cancel at period end
     * @param int $failedAttempts consecutive failed payment attempts
     * @param int $creditBalance available customer credit in minor units
     */
    public function __construct(
        public string $id,
        public Plan $plan,
        public string $status,
        public \DateTimeImmutable $anchor,
        public \DateTimeImmutable $currentPeriodStart,
        public \DateTimeImmutable $currentPeriodEnd,
        public ?\DateTimeImmutable $trialEnd = null,
        public bool $cancelAtPeriodEnd = false,
        public int $failedAttempts = 0,
        public int $creditBalance = 0,
    ) {}
}
