<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Immutable billing plan definition in integer minor currency units.
 */
final readonly class Plan
{
    /**
     * Maximum allowed price in minor units (100 billion).
     */
    public const int MAX_PRICE_MINOR = 100_000_000_000;

    /**
     * Maximum allowed identifier length.
     */
    public const int MAX_ID_LENGTH = 128;

    /**
     * Maximum allowed trial length in days.
     */
    public const int MAX_TRIAL_DAYS = 3650;

    /**
     * Creates a new billing plan.
     *
     * @param string $id unique plan identifier
     * @param int $priceMinor recurring price in minor currency units
     * @param string $currency 3-letter uppercase ISO 4217 currency code
     * @param string $interval billing interval: day, week, month, or year
     * @param int $intervalCount number of intervals per billing period
     * @param int $trialDays optional trial period length in days
     *
     * @throws SubscriptionException when any parameter violates domain constraints
     */
    public function __construct(
        public string $id,
        public int $priceMinor,
        public string $currency,
        public string $interval,
        public int $intervalCount = 1,
        public int $trialDays = 0,
    ) {
        if ('' === trim($id) || strlen($id) > self::MAX_ID_LENGTH) {
            throw new SubscriptionException('invalid_state', 'Plan ID must be non-empty and at most 128 bytes.');
        }

        if ($priceMinor < 0 || $priceMinor > self::MAX_PRICE_MINOR) {
            throw new SubscriptionException('invalid_price', 'Plan priceMinor must be between 0 and 100_000_000_000.');
        }

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new SubscriptionException('invalid_currency', 'Currency must be 3 uppercase ASCII letters.');
        }

        $maxCount = match ($interval) {
            'day' => 365,
            'week' => 52,
            'month' => 12,
            'year' => 1,
            default => throw new SubscriptionException('invalid_interval', 'Interval must be day, week, month, or year.'),
        };

        if ($intervalCount < 1 || $intervalCount > $maxCount) {
            throw new SubscriptionException(
                'invalid_interval',
                sprintf('Interval count for %s must be between 1 and %d.', $interval, $maxCount),
            );
        }

        if ($trialDays < 0 || $trialDays > self::MAX_TRIAL_DAYS) {
            throw new SubscriptionException('invalid_interval', 'Trial days must be between 0 and 3650.');
        }
    }
}
