<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Ranker;

use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\Contract\Routing\UsageTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

final readonly class UsageRanker implements RankerInterface
{
    public function __construct(
        private ?UsageTrackerInterface $tracker = null,
    ) {}

    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        $usage = $this->tracker !== null
            ? $this->tracker->getUsage($evaluation->candidate->id)
            : 0;

        $score = 1.0 / (1.0 + (float) $usage);

        return new RankScore($score, 'UsageRanker', ['usage' => $usage]);
    }
}
