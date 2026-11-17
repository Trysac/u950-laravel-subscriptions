<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Exact integer proration calculator using round-half-up arithmetic.
 */
final class Proration
{
    /**
     * Maximum allowed period duration in seconds (366 days).
     */
    public const int MAX_PERIOD_SECONDS = 366 * 86_400;

    /**
     * Calculates credit, charge, and net amounts for a mid-period plan change.
     *
     * @param int $oldMinor old plan price in minor units
     * @param int $newMinor new plan price in minor units
     * @param \DateTimeImmutable $start period start instant
     * @param \DateTimeImmutable $end period end instant
     * @param \DateTimeImmutable $at plan change instant
     *
     * @throws SubscriptionException when prices or dates are invalid
     */
    public static function calculate(
        int $oldMinor,
        int $newMinor,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        \DateTimeImmutable $at,
    ): ProrationResult {
        if ($oldMinor < 0 || $oldMinor > Plan::MAX_PRICE_MINOR || $newMinor < 0 || $newMinor > Plan::MAX_PRICE_MINOR) {
            throw new SubscriptionException('invalid_price', 'Plan prices must be between 0 and 100_000_000_000.');
        }

        $utc = new \DateTimeZone('UTC');
        $startUtc = $start->setTimezone($utc);
        $endUtc = $end->setTimezone($utc);
        $atUtc = $at->setTimezone($utc);

        $startTs = $startUtc->getTimestamp();
        $endTs = $endUtc->getTimestamp();
        $atTs = $atUtc->getTimestamp();

        $total = $endTs - $startTs;
        if ($total <= 0 || $total > self::MAX_PERIOD_SECONDS) {
            throw new SubscriptionException('invalid_date', 'Period end must be after start and at most 366 days.');
        }

        if ($atTs < $startTs || $atTs > $endTs) {
            throw new SubscriptionException('invalid_date', 'Proration timestamp must fall within [start, end].');
        }

        $remaining = $endTs - $atTs;
        $credit = intdiv(2 * $oldMinor * $remaining + $total, 2 * $total);
        $charge = intdiv(2 * $newMinor * $remaining + $total, 2 * $total);

        return new ProrationResult(
            credit: $credit,
            charge: $charge,
            net: $charge - $credit,
        );
    }
}
