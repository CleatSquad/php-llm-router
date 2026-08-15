<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Routing\ReliabilityTrackerInterface;

final class InMemoryReliabilityTracker implements ReliabilityTrackerInterface
{
    /** @var array<string, array{successes: int, failures: int}> */
    private array $stats = [];

    /**
     * @param int $minSamples Minimum number of total calls before calculating a success rate
     */
    public function __construct(
        private readonly int $minSamples = 5
    ) {}

    public function getSuccessRate(string $driverId): ?float
    {
        $data = $this->stats[$driverId] ?? ['successes' => 0, 'failures' => 0];
        $total = $data['successes'] + $data['failures'];

        if ($total < $this->minSamples) {
            return null;
        }

        return $data['successes'] / $total;
    }

    public function recordSuccess(string $driverId): void
    {
        if (!isset($this->stats[$driverId])) {
            $this->stats[$driverId] = ['successes' => 0, 'failures' => 0];
        }
        $this->stats[$driverId]['successes']++;
    }

    public function recordFailure(string $driverId): void
    {
        if (!isset($this->stats[$driverId])) {
            $this->stats[$driverId] = ['successes' => 0, 'failures' => 0];
        }
        $this->stats[$driverId]['failures']++;
    }
}
