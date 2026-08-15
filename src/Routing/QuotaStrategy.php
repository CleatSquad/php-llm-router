<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\QuotaTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class QuotaStrategy implements RoutingStrategyInterface
{
    public function __construct(
        private readonly QuotaTrackerInterface $tracker,
        private readonly ?RoutingStrategyInterface $fallbackStrategy = null
    ) {}

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        if (empty($drivers)) {
            throw new RuntimeException('No LLM drivers provided to the routing strategy.');
        }

        $available = array_values(array_filter($drivers, static fn (LLMDriverInterface $d) => $d->isAvailable()));

        if (empty($available)) {
            throw new RuntimeException('All configured LLM drivers are currently unavailable.');
        }

        // Hard constraint: exclude drivers with 0 quota remaining
        $eligible = array_values(array_filter($available, fn (LLMDriverInterface $driver): bool => !$this->tracker->isQuotaExceeded($driver->getId())));

        $candidates = !empty($eligible) ? $eligible : $available;

        // Rank by highest remaining quota ratio
        $bestDriver = null;
        $maxRatio = -1.0;

        foreach ($candidates as $driver) {
            $ratio = $this->tracker->getQuotaRemainingRatio($driver->getId()) ?? 1.0;
            if ($ratio > $maxRatio) {
                $maxRatio = $ratio;
                $bestDriver = $driver;
            }
        }

        if ($this->fallbackStrategy !== null && $bestDriver !== null) {
            // Find all drivers tied for highest remaining quota
            $topCandidates = array_values(array_filter($candidates, fn (LLMDriverInterface $d): bool =>
                abs(($this->tracker->getQuotaRemainingRatio($d->getId()) ?? 1.0) - $maxRatio) < 0.0001
            ));

            if (count($topCandidates) > 1) {
                return $this->fallbackStrategy->select($request, $topCandidates);
            }
        }

        return $bestDriver ?? $candidates[0];
    }
}
