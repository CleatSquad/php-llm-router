<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Constraint;

use CleatSquad\LlmRouter\Contract\Constraint\ConstraintInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\CandidateRejection;

final readonly class ModelConstraint implements ConstraintInterface
{
    public function evaluate(CandidateEvaluation $evaluation, LLMRequest $request): bool
    {
        if ($request->model === null) {
            return true;
        }

        $candidate = $evaluation->candidate;

        $matches = $candidate->model !== null
            ? $candidate->model === $request->model
            : in_array($request->model, $candidate->driver->getModels(), true);

        if (!$matches) {
            $evaluation->reject(new CandidateRejection(
                'ModelConstraint',
                'model_mismatch',
                sprintf('Candidate "%s" does not support requested model "%s".', $candidate->id, $request->model),
                [
                    'requested_model' => $request->model,
                    'candidate_model' => $candidate->model,
                    'supported_models' => $candidate->driver->getModels(),
                ]
            ));
            return false;
        }

        return true;
    }
}
