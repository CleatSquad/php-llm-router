<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Selector;

use CleatSquad\LlmRouter\Contract\Selector\SelectorInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;

final class RoundRobinSelector implements SelectorInterface
{
    private int $currentIndex = 0;

    public function select(array $eligibleEvaluations, LLMRequest $request): array
    {
        if (empty($eligibleEvaluations)) {
            return [];
        }

        $index = $this->currentIndex % count($eligibleEvaluations);
        $this->currentIndex++;

        $selected = $eligibleEvaluations[$index]->candidate;

        $remaining = [];
        foreach ($eligibleEvaluations as $i => $eval) {
            if ($i !== $index) {
                $remaining[] = $eval;
            }
        }

        usort($remaining, static function (CandidateEvaluation $a, CandidateEvaluation $b): int {
            $scoreA = $a->score !== null ? $a->score->value : 0.0;
            $scoreB = $b->score !== null ? $b->score->value : 0.0;
            return $scoreB <=> $scoreA;
        });

        $result = array_map(static fn (CandidateEvaluation $e): Candidate => $e->candidate, $remaining);
        array_unshift($result, $selected);

        return $result;
    }
}
