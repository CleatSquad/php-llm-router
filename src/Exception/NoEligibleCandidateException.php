<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Exception;

use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use RuntimeException;

final class NoEligibleCandidateException extends RuntimeException
{
    /**
     * @param CandidateEvaluation[] $evaluations
     */
    public function __construct(
        private readonly array $evaluations,
        string $message = 'No eligible LLM candidate passed routing constraints.'
    ) {
        parent::__construct($message);
    }

    /**
     * @return CandidateEvaluation[]
     */
    public function getEvaluations(): array
    {
        return $this->evaluations;
    }
}
