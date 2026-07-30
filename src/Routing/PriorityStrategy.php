<?php

declare(strict_types=1);

namespace Concio\LlmRouter\Routing;

use Concio\LlmRouter\Contract\Driver\LLMDriverInterface;
use Concio\LlmRouter\Contract\RoutingStrategyInterface;
use Concio\LlmRouter\DTO\LLMRequest;
use RuntimeException;

class PriorityStrategy implements RoutingStrategyInterface
{
    /**
     * @param array<string, int> $priorities Map of driver ID to priority
     *   (higher value = higher priority) — default map, used unless the
     *   request opts into $qualityPriorities via LLMRequest::$preferQuality.
     * @param array<string, int> $qualityPriorities Same shape, used instead
     *   of $priorities for requests where reasoning/tool-use quality matters
     *   more than raw speed/cost (see LLMRequest::$preferQuality). Falls
     *   back to $priorities when left empty, so callers that don't need the
     *   distinction (most tests, or an environment with only one sensible
     *   order) don't have to configure both.
     */
    public function __construct(
        private array $priorities = [],
        private array $qualityPriorities = []
    ) {}

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        if (empty($drivers)) {
            throw new RuntimeException('No LLM drivers provided to the routing strategy.');
        }

        $priorities = ($request->preferQuality && !empty($this->qualityPriorities))
            ? $this->qualityPriorities
            : $this->priorities;

        $candidates = [];
        foreach ($drivers as $driver) {
            $priority = $priorities[$driver->getId()] ?? 0;
            $candidates[] = [
                'driver' => $driver,
                'priority' => $priority,
            ];
        }

        // Sort descending by priority value
        usort($candidates, fn(array $a, array $b) => $b['priority'] <=> $a['priority']);

        foreach ($candidates as $candidate) {
            /** @var LLMDriverInterface $driver */
            $driver = $candidate['driver'];
            if ($driver->isAvailable()) {
                return $driver;
            }
        }

        throw new RuntimeException('All configured LLM drivers are currently unavailable.');
    }
}
