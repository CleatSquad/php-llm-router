<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Routing\QuotaTrackerInterface;

final class InMemoryQuotaTracker implements QuotaTrackerInterface
{
    /** @var array<string, float> Driver ID -> remaining ratio [0.0..1.0] */
    private array $ratios = [];

    /**
     * @param array<string, float> $initialRatios
     */
    public function __construct(array $initialRatios = [])
    {
        foreach ($initialRatios as $driverId => $ratio) {
            $this->setQuotaRemainingRatio($driverId, $ratio);
        }
    }

    public function setQuotaRemainingRatio(string $driverId, float $ratio): void
    {
        $this->ratios[$driverId] = min(max($ratio, 0.0), 1.0);
    }

    public function getQuotaRemainingRatio(string $driverId): ?float
    {
        return $this->ratios[$driverId] ?? null;
    }

    public function isQuotaExceeded(string $driverId): bool
    {
        return isset($this->ratios[$driverId]) && $this->ratios[$driverId] <= 0.0;
    }
}
