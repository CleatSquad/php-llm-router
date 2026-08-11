<?php

declare(strict_types=1);

namespace LlmRouter\Exception;

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
 * A RuntimeException, like UnknownModelException, so FailoverDriver can move to
 * a driver whose model does reason rather than aborting the whole request.
 */
final class UnsupportedReasoningException extends RuntimeException
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
