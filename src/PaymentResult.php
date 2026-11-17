<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Outcome of charging an invoice through a payment gateway.
 */
final readonly class PaymentResult
{
    /**
     * Creates a new PaymentResult.
     *
     * @param bool $approved true if the charge succeeded, false if declined
     * @param ?string $reason optional decline reason code
     */
    public function __construct(
        public bool $approved,
        public ?string $reason = null,
    ) {}

    /**
     * Creates an approved PaymentResult.
     */
    public static function approved(): self
    {
        return new self(true);
    }

    /**
     * Creates a declined PaymentResult.
     *
     * @param string $reason optional reason for the decline
     */
    public static function declined(string $reason = 'declined'): self
    {
        return new self(false, $reason);
    }
}
