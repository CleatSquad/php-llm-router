<?php

declare(strict_types=1);

namespace LlmRouter\Routing;

use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Contract\RoutingStrategyInterface;
use LlmRouter\DTO\LLMRequest;
use RuntimeException;

/**
 * Rotates across equivalent deployments of the same model (e.g. several API keys) instead of always picking the same one first.
 * Optional integer weights bias the rotation (weight 3 = offered 3x as often as weight 1, default 1); unavailable drivers are skipped without consuming a turn.
 */
final class RoundRobinStrategy implements RoutingStrategyInterface
{
    private int $cursor = 0;

    /**
     * @param array<string, int> $weights Map of driver ID to weight
     *   (higher = offered more often). Missing entries default to 1.
     */
    public function __construct(
        private readonly array $weights = []
    ) {}

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        if (empty($drivers)) {
            throw new RuntimeException('No LLM drivers provided to the routing strategy.');
        }

        $sequence = $this->weightedSequence($drivers);
        $available = array_values(array_filter($sequence, static fn(LLMDriverInterface $d) => $d->isAvailable()));

        if (empty($available)) {
            throw new RuntimeException('All configured LLM drivers are currently unavailable.');
        }

        $driver = $available[$this->cursor % count($available)];
        $this->cursor++;

        return $driver;
    }

    /**
     * @param LLMDriverInterface[] $drivers
     * @return LLMDriverInterface[]
     */
    private function weightedSequence(array $drivers): array
    {
        $sequence = [];
        foreach ($drivers as $driver) {
            $weight = max(1, $this->weights[$driver->getId()] ?? 1);
            for ($i = 0; $i < $weight; $i++) {
                $sequence[] = $driver;
            }
        }
        return $sequence;
    }
}
