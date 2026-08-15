<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Ranker;

use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

final readonly class PriorityRanker implements RankerInterface
{
    /**
     * @param array<string, int> $priorities
     * @param array<string, int> $qualityPriorities
     */
    public function __construct(
        private array $priorities = [],
        private array $qualityPriorities = [],
    ) {}

    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        $map = ($request->preferQuality && !empty($this->qualityPriorities))
            ? $this->qualityPriorities
            : $this->priorities;

        $id = $evaluation->candidate->id;
        $priority = (float) ($map[$id] ?? 0);

        return new RankScore($priority, 'PriorityRanker', ['priority' => $priority]);
    }
}
