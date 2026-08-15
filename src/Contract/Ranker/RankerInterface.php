<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Ranker;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;

interface RankerInterface
{
    public function score(CandidateEvaluation $evaluation, LLMRequest $request): RankScore;
}
