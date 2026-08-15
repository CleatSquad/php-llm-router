<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Routing;

interface ActiveRequestsTrackerInterface
{
    /**
     * Get the count of currently in-flight / active requests for a given driver ID.
     */
    public function getActiveRequests(string $driverId): int;

    /**
     * Increment active request count when a request starts.
     */
    public function increment(string $driverId): void;

    /**
     * Decrement active request count when a request finishes.
     */
    public function decrement(string $driverId): void;
}
