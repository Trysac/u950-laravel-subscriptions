<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Generator for unique invoice numbers.
 */
interface InvoiceNumberer
{
    /**
     * Allocates and returns the next invoice number.
     */
    public function next(): string;
}
