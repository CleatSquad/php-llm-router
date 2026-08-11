<?php

declare(strict_types=1);

namespace LlmRouter\Driver\Concern;

use LlmRouter\Exception\UnknownModelException;

/**
 * Model resolution for the drivers whose PRICING table doubles as their model
 * catalogue (Claude, OpenAI, Gemini, DeepSeek, Groq, Mistral — six copies of
 * the same code before this trait).
 *
 * The behaviour it replaces was:
 *
 *     return isset(self::PRICING[$model]) ? $model : 'gpt-4o-mini';
 *
 * — an unknown model silently became the driver's default. You asked for
 * "gpt-5", you got "gpt-4o-mini", and both the response and the cost estimate
 * reported the substitute as though you had chosen it. Nothing in the return
 * value distinguished that from a request that was honoured.
 *
 * Now an explicitly requested unknown model raises UnknownModelException.
 * Passing no model at all still resolves to the driver's default: that is a
 * caller declining to choose, not a caller being overruled.
 *
 * Because this table is also the pricing table, a model missing from it can't
 * be costed either. $extraModelPricing lets callers register a model this
 * release predates without waiting for a new version of the package.
 *
 * Ollama and Kimi deliberately do not use this trait: Ollama resolves against
 * the models actually installed on the local server (fuzzy matching that a
 * dedicated fix, ad6c8f5, tuned), and Kimi passes the requested name straight
 * through to the provider.
 */
trait ResolvesPricedModel
{
    /** @var array<string, array{input: float, output: float}> */
    private array $extraModelPricing = [];

    /**
     * Every model this driver can serve and price: the shipped table, plus
     * whatever the caller registered.
     *
     * @return array<string, array{input: float, output: float}>
     */
    private function modelPricing(): array
    {
        // Caller-supplied entries win, so a stale shipped price can be
        // corrected without editing the package.
        return $this->extraModelPricing + self::PRICING;
    }

    /**
     * @return array{input: float, output: float}
     */
    private function pricingFor(string $model): array
    {
        return $this->modelPricing()[$model] ?? self::PRICING[self::DEFAULT_MODEL];
    }

    /**
     * @throws UnknownModelException when a model is explicitly requested and
     *   this driver has no pricing for it.
     */
    private function resolveModel(?string $model): string
    {
        if ($model === null) {
            return self::DEFAULT_MODEL;
        }

        // "anthropic/claude-sonnet-5" and "claude-sonnet-5" name the same
        // model; callers routing through a proxy often carry the prefix.
        if (str_contains($model, '/')) {
            $model = explode('/', $model)[1];
        }

        if (isset($this->modelPricing()[$model])) {
            return $model;
        }

        throw new UnknownModelException(
            static::class,
            $model,
            array_keys($this->modelPricing()),
        );
    }
}
