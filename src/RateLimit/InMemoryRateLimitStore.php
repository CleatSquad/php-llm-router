<?php

declare(strict_types=1);

namespace LlmRouter\RateLimit;

/**
 * Default RateLimitStoreInterface: plain array, lives only for the
 * current process. Fine for a CLI session or a single long-lived
 * worker; a multi-process web app wanting a quota shared across
 * requests should implement the interface against Redis/DB instead.
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
