<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Clock abstraction providing the current instant.
 */
interface ClockInterface
{
    /**
     * Returns the current time.
     */
    public function now(): \DateTimeImmutable;
}
