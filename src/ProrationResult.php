<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Result of an exact integer proration calculation.
 */
final readonly class ProrationResult
{
    /**
     * Creates a new ProrationResult.
     *
     * @param int $credit unused amount credited from the old plan in minor units
     * @param int $charge amount charged for the new plan in minor units
     * @param int $net net difference ($charge - $credit) in minor units
     */
    public function __construct(
        public int $credit,
        public int $charge,
        public int $net,
    ) {}
}
