<?php

declare(strict_types=1);

namespace LlmRouter\RateLimit;

/**
 * Where RateLimitedDriver persists its per-driver usage window. The
 * default is a same-process array (InMemoryRateLimitStore); implement
 * this against Redis/DB/etc. to share a quota across requests or
 * worker processes.
 */
interface RateLimitStoreInterface
{
    public function getWindow(string $driverId): ?RateLimitWindow;

    public function saveWindow(string $driverId, RateLimitWindow $window): void;
}
