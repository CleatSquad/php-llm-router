<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Selector;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;

interface SelectorInterface
{
    /**
     * @param CandidateEvaluation[] $eligibleEvaluations
     * @return Candidate[]
     */
    public function select(array $eligibleEvaluations, LLMRequest $request): array;
}
