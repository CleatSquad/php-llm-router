<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\RateLimit;

use DateTimeImmutable;

/**
 * Usage counted so far in the current fixed window: how many requests
 * have gone out, and how many tokens they consumed.
 */
final readonly class RateLimitWindow
{
    public function __construct(
        public DateTimeImmutable $startedAt,
        public int $requestCount = 0,
        public int $tokenCount = 0,
    ) {}

    public static function startingAt(DateTimeImmutable $now): self
    {
        return new self($now);
    }

    public function isExpired(DateTimeImmutable $now, int $windowSeconds): bool
    {
        return $this->startedAt->modify(sprintf('+%d seconds', $windowSeconds)) <= $now;
    }

    public function withUsage(int $tokens): self
    {
        return new self($this->startedAt, $this->requestCount + 1, $this->tokenCount + $tokens);
    }
}
