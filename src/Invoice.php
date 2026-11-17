<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Immutable billing invoice with integer minor-unit line items.
 */
final readonly class Invoice
{
    /**
     * Creates a new Invoice.
     *
     * @param string $number sequential invoice number
     * @param string $currency 3-letter uppercase currency code
     * @param list<InvoiceLine> $lines ordered list of invoice lines
     * @param int $total total payable amount in minor units
     * @param string $status invoice status: open, paid, or void
     */
    public function __construct(
        public string $number,
        public string $currency,
        public array $lines,
        public int $total,
        public string $status,
    ) {}

    /**
     * Returns a copy of this invoice with an updated status.
     *
     * @param string $status new status: open, paid, or void
     */
    public function withStatus(string $status): self
    {
        return new self(
            $this->number,
            $this->currency,
            $this->lines,
            $this->total,
            $status,
        );
    }
}
