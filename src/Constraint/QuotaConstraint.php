<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Constraint;

use CleatSquad\LlmRouter\Contract\Constraint\ConstraintInterface;
use CleatSquad\LlmRouter\Contract\Routing\QuotaTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\CandidateRejection;

final readonly class QuotaConstraint implements ConstraintInterface
{
    public function __construct(
        private ?QuotaTrackerInterface $tracker = null,
    ) {}

    public function evaluate(CandidateEvaluation $evaluation, LLMRequest $request): bool
    {
        if ($this->tracker === null) {
            return true;
        }

        if ($this->tracker->isQuotaExceeded($evaluation->candidate->id)) {
            $evaluation->reject(new CandidateRejection(
                'QuotaConstraint',
                'quota_exhausted',
                'Driver quota limit has been exhausted'
            ));
            return false;
        }

        return true;
    }
}
