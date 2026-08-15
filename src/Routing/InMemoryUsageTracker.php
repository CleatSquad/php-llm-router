<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Routing\UsageTrackerInterface;

final class InMemoryUsageTracker implements UsageTrackerInterface
{
    /** @var array<string, int> */
    private array $usage = [];

    public function getUsage(string $driverId): int
    {
        return $this->usage[$driverId] ?? 0;
    }

    public function recordUsage(string $driverId, int $amount): void
    {
        $this->usage[$driverId] = ($this->usage[$driverId] ?? 0) + max(0, $amount);
    }
}
