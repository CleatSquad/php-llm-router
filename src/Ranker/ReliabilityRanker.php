<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Ranker;

use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\Contract\Routing\ReliabilityTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

final readonly class ReliabilityRanker implements RankerInterface
{
    public function __construct(
        private ?ReliabilityTrackerInterface $tracker = null,
        private float $defaultScore = 1.0,
    ) {
    }

    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        $successRate = $this->tracker !== null
            ? ($this->tracker->getSuccessRate($evaluation->candidate->id) ?? $this->defaultScore)
            : $this->defaultScore;

        return new RankScore($successRate, 'ReliabilityRanker', ['success_rate' => $successRate]);
    }
}
