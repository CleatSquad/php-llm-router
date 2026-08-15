<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Selector;

use CleatSquad\LlmRouter\Contract\Selector\SelectorInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;

final readonly class BestCandidateSelector implements SelectorInterface
{
    public function select(array $eligibleEvaluations, LLMRequest $request): array
    {
        if (empty($eligibleEvaluations)) {
            return [];
        }

        $sorted = $eligibleEvaluations;
        usort($sorted, static function (CandidateEvaluation $a, CandidateEvaluation $b): int {
            $scoreA = $a->score !== null ? $a->score->value : 0.0;
            $scoreB = $b->score !== null ? $b->score->value : 0.0;
            return $scoreB <=> $scoreA;
        });

        return array_map(static fn (CandidateEvaluation $e): Candidate => $e->candidate, $sorted);
    }
}
