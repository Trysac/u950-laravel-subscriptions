<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Base exception for subscription billing errors with a stable machine-readable code.
 */
class SubscriptionException extends \InvalidArgumentException
{
    /**
     * Creates a new SubscriptionException.
     *
     * @param string $errorCode machine-readable error identifier
     * @param string $message human-readable error description (truncated if needed)
     * @param ?\Throwable $previous optional previous exception
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        $safeMessage = strlen($message) > 200 ? substr($message, 0, 197) . '...' : $message;
        parent::__construct($safeMessage, 0, $previous);
    }
}
