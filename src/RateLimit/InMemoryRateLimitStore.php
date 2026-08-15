<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\RateLimit;

/**
 * Default RateLimitStoreInterface: a same-process array, fine for a CLI session or single worker.
 * Use RedisRateLimitStore instead when the quota needs to be shared across requests or processes.
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, RateLimitWindow> */
    private array $windows = [];

    public function getWindow(string $driverId): ?RateLimitWindow
    {
        return $this->windows[$driverId] ?? null;
    }

    public function saveWindow(string $driverId, RateLimitWindow $window): void
    {
        $this->windows[$driverId] = $window;
    }
}
