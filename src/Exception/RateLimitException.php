<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Exception;

use CleatSquad\LlmRouter\Contract\Exception\ExecutionFailureInterface;
use RuntimeException;

/**
 * The provider refused to serve right now. An ExecutionFailureInterface: the
 * instruction was sound, so another candidate — or this one after
 * getRetryAfterSeconds() — may well answer it.
 */
final class RateLimitException extends RuntimeException implements ExecutionFailureInterface
{
    public function __construct(
        string $message,
        private readonly ?int $retryAfterSeconds = null,
        private readonly ?int $statusCode = 429,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $statusCode ?? 0,
            $previous
        );
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
