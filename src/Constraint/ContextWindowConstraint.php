<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Constraint;

use CleatSquad\LlmRouter\Contract\Constraint\ConstraintInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\CandidateRejection;

final readonly class ContextWindowConstraint implements ConstraintInterface
{
    /**
     * @param array<string, int> $maxContextTokens
     */
    public function __construct(
        private array $maxContextTokens = []
    ) {}

    public function evaluate(CandidateEvaluation $evaluation, LLMRequest $request): bool
    {
        $candidateId = $evaluation->candidate->id;
        $limit = $this->maxContextTokens[$candidateId] ?? PHP_INT_MAX;
        $estimatedInputTokens = $request->estimateInputTokens();

        if ($estimatedInputTokens > $limit) {
            $evaluation->reject(new CandidateRejection(
                'ContextWindowConstraint',
                'insufficient_context_window',
                sprintf('Request estimated tokens (%d) exceeds max limit (%d)', $estimatedInputTokens, $limit),
                ['estimated_tokens' => $estimatedInputTokens, 'limit' => $limit]
            ));
            return false;
        }

        return true;
    }
}
