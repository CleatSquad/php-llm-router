<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Constraint;

use CleatSquad\LlmRouter\Contract\Constraint\ConstraintInterface;
use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCapabilitiesInterface;
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
    ) {
    }

    public function evaluate(CandidateEvaluation $evaluation, LLMRequest $request): bool
    {
        $driver = $evaluation->candidate->driver;
        $model = $evaluation->candidate->model;
        $wantsTools = $this->requireTools || !empty($request->tools);
        $wantsReasoning = $this->requireReasoning || $request->wantsReasoning();
        $wantsStreaming = $this->requireStreaming || $request->stream;

        // a model-level flag (when the driver declares one) wins
        // over the driver-wide method — a driver can serve models that
        // differ from each other, "reasoning" already proved this pattern
        // (ResolvesPricedModel::pricingFor()['reasoning']).
        $supportsTools = $this->modelFlag($driver, $model, 'supportsToolsFor') ?? $driver->supportsTools();
        $supportsVision = $this->modelFlag($driver, $model, 'supportsVisionFor') ?? $driver->supportsVision();

        if ($wantsTools && !$supportsTools) {
            $evaluation->reject(new CandidateRejection('CapabilityConstraint', 'missing_tools', 'Driver does not support tool calling'));
            return false;
        }
        if ($this->requireVision && !$supportsVision) {
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

    private function modelFlag(LLMDriverInterface $driver, ?string $model, string $method): ?bool
    {
        if ($model === null || !$driver instanceof ModelCapabilitiesInterface) {
            return null;
        }

        return $driver->$method($model);
    }
}
