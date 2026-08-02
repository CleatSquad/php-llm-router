<?php

declare(strict_types=1);

namespace LlmRouter\RateLimit;

/**
 * Where RateLimitedDriver persists its per-driver usage window; implement against Redis/DB to share a quota across processes.
 */
interface RateLimitStoreInterface
{
    public function getWindow(string $driverId): ?RateLimitWindow;

    public function saveWindow(string $driverId, RateLimitWindow $window): void;
}
