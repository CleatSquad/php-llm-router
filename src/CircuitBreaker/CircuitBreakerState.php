<?php

declare(strict_types=1);

namespace LlmRouter\CircuitBreaker;

use DateTimeImmutable;

/**
 * A driver's circuit-breaker bookkeeping: how many consecutive
 * chat()/stream() failures it has racked up, and — once that hits the
 * threshold — the timestamp until which it should be treated as
 * unavailable without even trying.
 */
final readonly class CircuitBreakerState
{
    public function __construct(
        public int $failureCount = 0,
        public ?DateTimeImmutable $openUntil = null,
    ) {}

    public function isOpen(DateTimeImmutable $now): bool
    {
        return $this->openUntil !== null && $this->openUntil > $now;
    }

    public function withFailure(int $threshold, int $openSeconds, DateTimeImmutable $now): self
    {
        $failureCount = $this->failureCount + 1;
        $openUntil = $failureCount >= $threshold
            ? $now->modify(sprintf('%+d seconds', $openSeconds))
            : $this->openUntil;

        return new self($failureCount, $openUntil);
    }

    public function reset(): self
    {
        return new self();
    }
}
