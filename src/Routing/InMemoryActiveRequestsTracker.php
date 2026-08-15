<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Routing\ActiveRequestsTrackerInterface;

final class InMemoryActiveRequestsTracker implements ActiveRequestsTrackerInterface
{
    /** @var array<string, int> */
    private array $active = [];

    public function getActiveRequests(string $driverId): int
    {
        return $this->active[$driverId] ?? 0;
    }

    public function increment(string $driverId): void
    {
        $this->active[$driverId] = ($this->active[$driverId] ?? 0) + 1;
    }

    public function decrement(string $driverId): void
    {
        $current = $this->active[$driverId] ?? 0;
        $this->active[$driverId] = max(0, $current - 1);
    }
}
