<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Ranker;

use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\Contract\Routing\ActiveRequestsTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

final readonly class LeastBusyRanker implements RankerInterface
{
    public function __construct(
        private ?ActiveRequestsTrackerInterface $tracker = null,
    ) {}

    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        $active = $this->tracker !== null
            ? $this->tracker->getActiveRequests($evaluation->candidate->id)
            : 0;

        $score = 1.0 / (1.0 + (float) $active);

        return new RankScore($score, 'LeastBusyRanker', ['active_requests' => $active]);
    }
}
