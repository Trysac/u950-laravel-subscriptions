<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Single line item on an invoice.
 */
final readonly class InvoiceLine
{
    /**
     * Creates a new invoice line item.
     *
     * @param string $description human-readable line description
     * @param int $amountMinor positive charge or negative credit in minor currency units
     */
    public function __construct(
        public string $description,
        public int $amountMinor,
    ) {}
}
