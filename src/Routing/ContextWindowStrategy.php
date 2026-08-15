<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class ContextWindowStrategy implements RoutingStrategyInterface
{
    /**
     * @param array<string, int> $maxContextTokens Map of driver ID or model to max context tokens limit
     * @param RoutingStrategyInterface|null $fallbackStrategy Secondary strategy to tie-break drivers that satisfy context requirement
     */
    public function __construct(
        private readonly array $maxContextTokens = [],
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

        $estimatedInputTokens = $request->estimateInputTokens();

        // Filter drivers that can support the estimated token size
        $eligible = array_values(array_filter($available, function (LLMDriverInterface $driver) use ($estimatedInputTokens): bool {
            $limit = $this->maxContextTokens[$driver->getId()] ?? PHP_INT_MAX;
            return $estimatedInputTokens <= $limit;
        }));

        if (!empty($eligible)) {
            if ($this->fallbackStrategy !== null) {
                return $this->fallbackStrategy->select($request, $eligible);
            }
            return $eligible[0];
        }

        // If no driver satisfies the context window limit, fall back to available drivers
        if ($this->fallbackStrategy !== null) {
            return $this->fallbackStrategy->select($request, $available);
        }

        return $available[0];
    }
}
