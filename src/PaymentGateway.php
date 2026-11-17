<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Payment gateway abstraction for charging invoices.
 */
interface PaymentGateway
{
    /**
     * Attempts to charge the total payable amount of an invoice.
     *
     * @param Invoice $i the invoice to charge
     */
    public function charge(Invoice $i): PaymentResult;
}
