<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Exception;

use CleatSquad\LlmRouter\Contract\Exception\RoutingFailureInterface;
use RuntimeException;

/**
 * Thrown when a reasoning effort is requested from a model that cannot reason.
 *
 * Providers reject the reasoning parameter on such models with an opaque 400 —
 * OpenAI does exactly that for `reasoning_effort` on `gpt-4o`. Failing here
 * instead turns "400 Bad Request" into a message naming the model, the driver,
 * and the models that would have worked.
 *
 * The check is only as good as the driver's catalogue: a model registered
 * through $extraModelPricing is assumed to reason unless its entry says
 * `'reasoning' => false`, and drivers without a pricing table (Kimi, Ollama)
 * don't perform this check at all — there, the provider's own error stands.
 *
 * A RoutingFailureInterface, like UnknownModelException: whether a model can
 * reason is knowable before the call, so a candidate that cannot serve a
 * reasoning request should have been rejected by a constraint. Reaching this
 * point means the plan was built wrong, and CapabilityConstraint is where to
 * fix it. PlanExecutor surfaces it rather than working around it silently.
 *
 * FailoverDriver keeps failing over on it, as it always has.
 */
final class UnsupportedReasoningException extends RuntimeException implements RoutingFailureInterface
{
    /**
     * @param string[] $reasoningModels
     */
    public function __construct(
        public readonly string $driverId,
        public readonly string $model,
        public readonly array $reasoningModels,
    ) {
        parent::__construct(sprintf(
            'Reasoning was requested but "%s" is not a reasoning model on %s. %s',
            $model,
            $driverId,
            $reasoningModels === []
                ? 'This driver knows of no reasoning model; register one with its pricing '
                    . 'through $extraModelPricing, or drop reasoningEffort from the request.'
                : 'Models that reason here: ' . implode(', ', $reasoningModels) . '.',
        ));
    }
}
