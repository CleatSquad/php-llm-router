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
    ) {}

    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        $successRate = $this->tracker !== null
            ? ($this->tracker->getSuccessRate($evaluation->candidate->id) ?? 1.0)
            : 1.0;

        return new RankScore($successRate, 'ReliabilityRanker', ['success_rate' => $successRate]);
    }
}
