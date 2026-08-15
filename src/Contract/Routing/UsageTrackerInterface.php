<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Routing;

interface UsageTrackerInterface
{
    /**
     * Get recorded total tokens or request count for a given driver ID within current window.
     */
    public function getUsage(string $driverId): int;

    /**
     * Record usage (e.g. tokens used or requests completed) for a given driver ID.
     */
    public function recordUsage(string $driverId, int $amount): void;
}
