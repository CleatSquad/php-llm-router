<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Ranker;

use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

final readonly class CostRanker implements RankerInterface
{
    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        $estimate = $evaluation->candidate->driver->estimateCost($request);
        $cost = $estimate->estimatedCostUsd;
        $score = 1.0 / (1.0 + $cost);

        return new RankScore($score, 'CostRanker', ['estimated_cost' => $cost]);
    }
}
