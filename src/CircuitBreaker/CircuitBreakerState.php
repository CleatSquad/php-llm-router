<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\CircuitBreaker;

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

    public function withFailure(
        int $threshold,
        int $openSeconds,
        DateTimeImmutable $now,
        ?int $retryAfterSeconds = null
    ): self {
        $failureCount = $this->failureCount + 1;

        if ($failureCount < $threshold) {
            return new self(
                $failureCount,
                $this->openUntil
            );
        }

        $cooldownSeconds = $retryAfterSeconds ?? $openSeconds;
        $cooldownSeconds = max(0, $cooldownSeconds);

        $openUntil = $now->modify("+{$cooldownSeconds} seconds");

        return new self(
            $failureCount,
            $openUntil
        );
    }

    public function reset(): self
    {
        return new self();
    }
}
