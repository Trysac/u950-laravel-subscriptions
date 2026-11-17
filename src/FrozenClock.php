<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Deterministic clock for testing subscription lifecycles.
 */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    /**
     * Creates a new FrozenClock fixed at the given time (converted to UTC).
     *
     * @param \DateTimeImmutable $now initial clock instant
     */
    public function __construct(\DateTimeImmutable $now)
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Returns the current frozen UTC timestamp.
     */
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Sets the clock to a specific instant (converted to UTC).
     *
     * @param \DateTimeImmutable $now new clock instant
     */
    public function setTo(\DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Advances the frozen clock by the given number of seconds.
     *
     * @param int $seconds number of seconds to advance
     */
    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
