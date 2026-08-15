<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Ranker;

use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

final readonly class CompositeRanker implements RankerInterface
{
    /**
     * @param array<int, array{ranker: RankerInterface, weight: float}> $weightedRankers
     */
    public function __construct(
        private array $weightedRankers = [],
    ) {}

    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore
    {
        if (empty($this->weightedRankers)) {
            return new RankScore(1.0, 'CompositeRanker');
        }

        $totalScore = 0.0;
        $totalWeight = 0.0;
        $breakdown = [];

        foreach ($this->weightedRankers as $item) {
            /** @var RankerInterface $ranker */
            $ranker = $item['ranker'];
            $weight = (float) $item['weight'];
            $subScore = $ranker->score($evaluation, $request);

            $totalScore += $subScore->value * $weight;
            $totalWeight += $weight;
            $breakdown[$subScore->ranker] = [
                'score' => $subScore->value,
                'weight' => $weight,
                'metadata' => $subScore->metadata,
            ];
        }

        $finalValue = $totalWeight > 0.0 ? ($totalScore / $totalWeight) : 0.0;

        return new RankScore($finalValue, 'CompositeRanker', ['breakdown' => $breakdown]);
    }
}
