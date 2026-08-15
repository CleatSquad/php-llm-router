<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Routing;

interface QuotaTrackerInterface
{
    /**
     * Get remaining quota ratio between 0.0 (exhausted/0%) and 1.0 (100% full capacity remaining).
     * Returns null if no quota limits are defined for this driver ID.
     */
    public function getQuotaRemainingRatio(string $driverId): ?float;

    /**
     * Check whether driver has exceeded hard quota limit.
     */
    public function isQuotaExceeded(string $driverId): bool;
}
