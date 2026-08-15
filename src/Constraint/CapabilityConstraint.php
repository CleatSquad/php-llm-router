<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Constraint;

use CleatSquad\LlmRouter\Contract\Constraint\ConstraintInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\CandidateRejection;

final readonly class CapabilityConstraint implements ConstraintInterface
{
    public function __construct(
        private bool $requireTools = false,
        private bool $requireVision = false,
        private bool $requireReasoning = false,
        private bool $requireStreaming = false,
    ) {}

    public function evaluate(CandidateEvaluation $evaluation, LLMRequest $request): bool
    {
        $driver = $evaluation->candidate->driver;
        $wantsTools = $this->requireTools || !empty($request->tools);
        $wantsReasoning = $this->requireReasoning || $request->wantsReasoning();
        $wantsStreaming = $this->requireStreaming || $request->stream;

        if ($wantsTools && !$driver->supportsTools()) {
            $evaluation->reject(new CandidateRejection('CapabilityConstraint', 'missing_tools', 'Driver does not support tool calling'));
            return false;
        }
        if ($this->requireVision && !$driver->supportsVision()) {
            $evaluation->reject(new CandidateRejection('CapabilityConstraint', 'missing_vision', 'Driver does not support vision'));
            return false;
        }
        if ($wantsReasoning && !$driver->supportsReasoning()) {
            $evaluation->reject(new CandidateRejection('CapabilityConstraint', 'missing_reasoning', 'Driver does not support reasoning'));
            return false;
        }
        if ($wantsStreaming && !$driver->supportsStreaming()) {
            $evaluation->reject(new CandidateRejection('CapabilityConstraint', 'missing_streaming', 'Driver does not support streaming'));
            return false;
        }

        return true;
    }
}
