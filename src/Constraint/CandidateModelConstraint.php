<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Constraint;

use CleatSquad\LlmRouter\Contract\Constraint\ConstraintInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\CandidateRejection;

/**
 * Rejects a candidate whose driver cannot serve the candidate's own model.
 *
 * A different question from ModelConstraint, hence a different class:
 * ModelConstraint asks whether a candidate satisfies what the *caller* asked
 * for, and stands down when the caller asked for nothing. This one asks
 * whether the candidate is executable at all, which has an answer either way.
 *
 * A candidate carrying no model is always eligible: it means "let the driver
 * choose", and every driver can.
 */
final readonly class CandidateModelConstraint implements ConstraintInterface
{
    public function evaluate(CandidateEvaluation $evaluation, LLMRequest $request): bool
    {
        $candidate = $evaluation->candidate;
        $model = $candidate->model;

        if ($model === null) {
            return true;
        }

        $driver = $candidate->driver;

        // A driver publishing no catalogue gets the benefit of the doubt: the
        // only safe assumption for one whose resolution rules we cannot know.
        if (!$driver instanceof ModelCatalogueInterface) {
            return true;
        }

        if ($driver->supportsModel($model)) {
            return true;
        }

        $evaluation->reject(new CandidateRejection(
            'CandidateModelConstraint',
            'candidate_model_unsupported',
            sprintf(
                'Candidate "%s" pairs driver "%s" with model "%s", which that driver cannot serve. '
                . 'This is a defect in how the candidate was built, not a provider failure.',
                $candidate->id,
                $driver->getId(),
                $model,
            ),
            [
                'candidate_model' => $model,
                'driver_id' => $driver->getId(),
                'supported_models' => $driver->getModels(),
            ]
        ));

        return false;
    }
}
