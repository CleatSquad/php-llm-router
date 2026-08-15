<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

use DateTimeImmutable;

/**
 * Represents the health status of a driver.
 */
final readonly class HealthStatus
{
    /**
     * @param HealthState       $status    Current health state
     * @param int               $latencyMs Measured latency in milliseconds
     * @param string|null       $message   Optional message
     * @param DateTimeImmutable $checkedAt Timestamp of the health check
     */
    public function __construct(
        public HealthState $status,
        public int $latencyMs,
        public ?string $message,
        public DateTimeImmutable $checkedAt,
    ) {}

    /**
     * Quick healthy check.
     */
    public function isHealthy(): bool
    {
        return $this->status === HealthState::HEALTHY;
    }

    /**
     * Create a healthy status.
     */
    public static function healthy(int $latencyMs = 0, ?string $message = null): self
    {
        return new self(HealthState::HEALTHY, $latencyMs, $message, new DateTimeImmutable());
    }

    /**
     * Create an unhealthy status.
     */
    public static function unhealthy(string $message, int $latencyMs = 0): self
    {
        return new self(HealthState::UNHEALTHY, $latencyMs, $message, new DateTimeImmutable());
    }

    /**
     * Create an unknown status.
     */
    public static function unknown(?string $message = null): self
    {
        return new self(HealthState::UNKNOWN, 0, $message, new DateTimeImmutable());
    }
}
