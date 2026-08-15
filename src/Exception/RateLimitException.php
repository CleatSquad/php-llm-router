<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Exception;

use RuntimeException;

final class RateLimitException extends RuntimeException
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
