<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Adapter;

use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Selector\SelectorInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;

final class LegacyStrategyAdapter implements SelectorInterface
{
    public function __construct(
        private readonly RoutingStrategyInterface $legacyStrategy,
    ) {}

    public static function toPolicy(RoutingStrategyInterface $strategy, string $name = 'legacy'): RoutingPolicy
    {
        return new RoutingPolicy(
            constraints: [],
            rankers: [],
            selector: new self($strategy),
            name: $name
        );
    }

    public function select(array $eligibleEvaluations, LLMRequest $request): array
    {
        if (empty($eligibleEvaluations)) {
            return [];
        }

        $drivers = array_map(static fn (CandidateEvaluation $e): \CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface => $e->candidate->driver, $eligibleEvaluations);
        $selectedDriver = $this->legacyStrategy->select($request, $drivers);

        $selected = null;
        $remaining = [];
        foreach ($eligibleEvaluations as $eval) {
            if ($eval->candidate->driver->getId() === $selectedDriver->getId() && $selected === null) {
                $selected = $eval->candidate;
            } else {
                $remaining[] = $eval->candidate;
            }
        }

        if ($selected !== null) {
            array_unshift($remaining, $selected);
        }

        return $remaining;
    }
}
