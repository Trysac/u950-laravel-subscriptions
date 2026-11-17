<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Thrown when text normalization receives blank input.
 */
final class BlankInputException extends SubscriptionException
{
    /**
     * Creates a new BlankInputException.
     *
     * @param string $message the exception message
     * @param ?\Throwable $previous the underlying cause
     */
    public function __construct(string $message = 'input is blank', ?\Throwable $previous = null)
    {
        parent::__construct('invalid_state', $message, $previous);
    }
}
