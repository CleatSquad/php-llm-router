<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Exception;

use CleatSquad\LlmRouter\Contract\Exception\RoutingFailureInterface;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use RuntimeException;

/**
 * Thrown when every candidate was rejected before a plan could be built.
 *
 * A RoutingFailureInterface by definition: there is no plan to fall back
 * through. getEvaluations() carries why each candidate was dropped, which is
 * the answer to the only useful question here — whether the constraints were
 * too strict or the pool too small.
 */
final class NoEligibleCandidateException extends RuntimeException implements RoutingFailureInterface
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
