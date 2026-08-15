<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Constraint;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;

interface ConstraintInterface
{
    public function evaluate(CandidateEvaluation $evaluation, LLMRequest $request): bool;
}
