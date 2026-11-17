<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Calculates calendar-anchored UTC billing period boundaries.
 */
final class PeriodCalculator
{
    /**
     * Advances a period start timestamp by one plan billing period while preserving the anchor day.
     *
     * @param \DateTimeImmutable $anchor subscription billing anchor in UTC
     * @param \DateTimeImmutable $periodStart current period start in UTC
     * @param Plan $plan billing plan defining interval and intervalCount
     */
    public static function nextPeriodEnd(
        \DateTimeImmutable $anchor,
        \DateTimeImmutable $periodStart,
        Plan $plan,
    ): \DateTimeImmutable {
        $utc = new \DateTimeZone('UTC');
        $anchorUtc = $anchor->setTimezone($utc);
        $startUtc = $periodStart->setTimezone($utc);

        return match ($plan->interval) {
            'day' => $startUtc->modify(sprintf('+%d days', $plan->intervalCount)),
            'week' => $startUtc->modify(sprintf('+%d days', $plan->intervalCount * 7)),
            'month' => self::addAnchoredMonths($anchorUtc, $startUtc, $plan->intervalCount),
            'year' => self::addAnchoredMonths($anchorUtc, $startUtc, $plan->intervalCount * 12),
            default => throw new SubscriptionException('invalid_interval', 'Unsupported billing interval.'),
        };
    }

    /**
     * Adds a number of calendar months to a timestamp, clamping the day of month to the target month's length.
     */
    private static function addAnchoredMonths(
        \DateTimeImmutable $anchorUtc,
        \DateTimeImmutable $startUtc,
        int $monthsToAdd,
    ): \DateTimeImmutable {
        $anchorDay = (int) $anchorUtc->format('j');
        $startYear = (int) $startUtc->format('Y');
        $startMonth = (int) $startUtc->format('n');
        $hour = (int) $startUtc->format('G');
        $minute = (int) $startUtc->format('i');
        $second = (int) $startUtc->format('s');

        $totalMonths = ($startYear * 12 + ($startMonth - 1)) + $monthsToAdd;
        $targetYear = intdiv($totalMonths, 12);
        $targetMonth = ($totalMonths % 12) + 1;

        $daysInTargetMonth = self::daysInMonth($targetYear, $targetMonth);
        $targetDay = min($anchorDay, $daysInTargetMonth);

        return $startUtc
            ->setDate($targetYear, $targetMonth, $targetDay)
            ->setTime($hour, $minute, $second);
    }

    /**
     * Returns the number of days in the given Gregorian year and month.
     */
    private static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            1, 3, 5, 7, 8, 10, 12 => 31,
            4, 6, 9, 11 => 30,
            2 => self::isLeapYear($year) ? 29 : 28,
            default => 30,
        };
    }

    /**
     * Determines whether the given year is a leap year.
     */
    private static function isLeapYear(int $year): bool
    {
        return (0 === $year % 4 && 0 !== $year % 100) || (0 === $year % 400);
    }
}
