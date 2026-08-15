<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Ranker;

use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\Contract\Routing\LatencyTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

final readonly class LatencyRanker implements RankerInterface
{
    public function __construct(
        private ?LatencyTrackerInterface $tracker = null,
        private float $defaultLatencyMs = 100.0,
    ) {}

    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        $latencyMs = $this->tracker !== null
            ? ($this->tracker->getLatencyMs($evaluation->candidate->id) ?? $this->defaultLatencyMs)
            : $this->defaultLatencyMs;

        $score = 1.0 / (1.0 + ($latencyMs / 1000.0));

        return new RankScore($score, 'LatencyRanker', ['latency_ms' => $latencyMs]);
    }
}
