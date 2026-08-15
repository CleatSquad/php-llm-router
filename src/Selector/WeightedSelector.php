<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Selector;

use CleatSquad\LlmRouter\Contract\Routing\RandomizerInterface;
use CleatSquad\LlmRouter\Contract\Selector\SelectorInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Routing\NativeRandomizer;

final readonly class WeightedSelector implements SelectorInterface
{
    /**
     * @param array<string, float> $weights Candidate ID => weight
     */
    public function __construct(
        private array $weights = [],
        private ?RandomizerInterface $randomizer = null,
    ) {}

    public function select(array $eligibleEvaluations, LLMRequest $request): array
    {
        if (empty($eligibleEvaluations)) {
            return [];
        }

        $randomizer = $this->randomizer ?? new NativeRandomizer();

        $weightedMap = [];
        $totalWeight = 0.0;
        foreach ($eligibleEvaluations as $eval) {
            $candidateId = $eval->candidate->id;
            $w = $this->weights[$candidateId] ?? ($eval->score !== null ? $eval->score->value : 1.0);
            $w = max(0.0001, $w);
            $weightedMap[$candidateId] = $w;
            $totalWeight += $w;
        }

        $rand = $randomizer->nextFloat() * $totalWeight;
        $selectedId = null;
        $current = 0.0;
        foreach ($weightedMap as $id => $w) {
            $current += $w;
            if ($rand <= $current) {
                $selectedId = $id;
                break;
            }
        }

        $selectedId = $selectedId ?? $eligibleEvaluations[0]->candidate->id;

        $selected = null;
        $remaining = [];
        foreach ($eligibleEvaluations as $eval) {
            if ($eval->candidate->id === $selectedId && $selected === null) {
                $selected = $eval->candidate;
            } else {
                $remaining[] = $eval;
            }
        }

        usort($remaining, static function (CandidateEvaluation $a, CandidateEvaluation $b): int {
            $scoreA = $a->score !== null ? $a->score->value : 0.0;
            $scoreB = $b->score !== null ? $b->score->value : 0.0;
            return $scoreB <=> $scoreA;
        });

        $orderedCandidates = array_map(static fn (CandidateEvaluation $e): Candidate => $e->candidate, $remaining);
        if ($selected !== null) {
            array_unshift($orderedCandidates, $selected);
        }

        return $orderedCandidates;
    }
}
