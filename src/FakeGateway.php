<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Deterministic in-memory payment gateway with scripted outcomes.
 */
final class FakeGateway implements PaymentGateway
{
    /**
     * Maximum number of recorded charges retained in memory.
     */
    public const int MAX_HISTORY = 1000;

    /**
     * @var list<PaymentResult>
     */
    private array $queue = [];

    private PaymentResult $defaultResult;

    /**
     * @var list<Invoice>
     */
    private array $chargedInvoices = [];

    /**
     * Creates a FakeGateway with optional scripted outcomes.
     *
     * @param list<bool|PaymentResult> $scriptedResults FIFO queue of outcomes
     * @param bool $defaultApproved fallback outcome when the queue is empty
     */
    public function __construct(array $scriptedResults = [], bool $defaultApproved = true)
    {
        $this->defaultResult = new PaymentResult($defaultApproved, $defaultApproved ? null : 'declined');
        foreach ($scriptedResults as $item) {
            $this->enqueue($item);
        }
    }

    /**
     * Appends a scripted result to the gateway queue.
     *
     * @param bool|PaymentResult $result true/false or a PaymentResult instance
     */
    public function enqueue(bool|PaymentResult $result): void
    {
        if (is_bool($result)) {
            $this->queue[] = new PaymentResult($result, $result ? null : 'declined');
        } else {
            $this->queue[] = $result;
        }
    }

    /**
     * Charges an invoice and returns the next scripted outcome.
     *
     * @param Invoice $i the invoice being charged
     */
    public function charge(Invoice $i): PaymentResult
    {
        if (count($this->chargedInvoices) >= self::MAX_HISTORY) {
            array_shift($this->chargedInvoices);
        }
        $this->chargedInvoices[] = $i;

        if ([] !== $this->queue) {
            return array_shift($this->queue);
        }

        return $this->defaultResult;
    }

    /**
     * Returns the list of invoices passed to charge().
     *
     * @return list<Invoice>
     */
    public function chargedInvoices(): array
    {
        return $this->chargedInvoices;
    }
}
