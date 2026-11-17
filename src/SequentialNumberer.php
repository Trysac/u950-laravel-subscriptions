<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Generates sequential invoice numbers in the format INV-000001.
 */
final class SequentialNumberer implements InvoiceNumberer
{
    private int $counter;

    /**
     * Creates a SequentialNumberer starting at the given sequence number.
     *
     * @param int $startAt first number to issue (defaults to 1)
     */
    public function __construct(int $startAt = 1)
    {
        $this->counter = max(1, $startAt);
    }

    /**
     * Returns the next sequential invoice number.
     */
    public function next(): string
    {
        $number = sprintf('INV-%06d', $this->counter);
        ++$this->counter;

        return $number;
    }
}
