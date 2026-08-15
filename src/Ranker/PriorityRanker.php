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

        $candidate = $evaluation->candidate;
        $priorityValue = $map[$candidate->id]
            ?? ($candidate->model !== null ? ($map[$candidate->model] ?? null) : null)
            ?? ($map[$candidate->driver->getId()] ?? 0);
        $priority = (float) $priorityValue;

        return new RankScore($priority, 'PriorityRanker', ['priority' => $priority]);
    }
}
