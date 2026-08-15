<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver\Concern;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Exception\UnknownModelException;
use CleatSquad\LlmRouter\Exception\UnsupportedReasoningException;

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
    /** @var array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}> */
    private array $extraModelPricing = [];

    /**
     * Every model this driver can serve and price: the shipped table, plus
     * whatever the caller registered.
     *
     * @return array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}>
     */
    private function modelPricing(): array
    {
        // Caller-supplied entries win, so a stale shipped price can be
        // corrected without editing the package.
        return $this->extraModelPricing + self::PRICING;
    }

    /**
     * Entries may carry capability flags beside the rates: `reasoning => false`
     * marks a model that cannot reason, `thinkingAlwaysOn => true` one whose
     * thinking cannot be switched off, and `reasoningEffort`/`reasoningFormat`
     * record which spelling of the reasoning parameters a model accepts where
     * a provider serves more than one. Absent flags take the driver's default.
     *
     * @return array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}
     */
    private function pricingFor(string $model): array
    {
        return $this->modelPricing()[$model] ?? self::PRICING[self::DEFAULT_MODEL];
    }

    /**
     * Refuses a reasoning request the chosen model cannot serve.
     *
     * Providers answer that with an opaque 400 — OpenAI does exactly that for
     * `reasoning_effort` on gpt-4o. Failing here names the model, the driver,
     * and the models that would have worked instead.
     *
     * Only models the catalogue explicitly marks `reasoning => false` are
     * refused: a model registered through $extraModelPricing is trusted to
     * reason unless its entry says otherwise, so this never blocks a model
     * this release simply predates.
     *
     * @throws UnsupportedReasoningException
     */
    private function assertModelCanReason(string $model, LLMRequest $request): void
    {
        if (!$request->wantsReasoning()) {
            return;
        }

        $pricing = $this->modelPricing();

        if (($pricing[$model]['reasoning'] ?? true) !== false) {
            return;
        }

        throw new UnsupportedReasoningException(
            static::class,
            $model,
            array_keys(array_filter(
                $pricing,
                static fn (array $entry): bool => ($entry['reasoning'] ?? true) !== false
            )),
        );
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

        $pricing = $this->modelPricing();

        // Check the name as given first: some model IDs genuinely contain a
        // slash (Groq serves "openai/gpt-oss-120b"), and stripping eagerly
        // would turn a known model into an unknown one.
        if (isset($pricing[$model])) {
            return $model;
        }

        // Otherwise treat a slash as a provider prefix — "anthropic/claude-sonnet-5"
        // and "claude-sonnet-5" name the same model, and callers routing through
        // a proxy often carry the prefix.
        if (str_contains($model, '/')) {
            $withoutPrefix = explode('/', $model, 2)[1];

            if (isset($pricing[$withoutPrefix])) {
                return $withoutPrefix;
            }
        }

        throw new UnknownModelException(
            static::class,
            $model,
            array_keys($pricing),
        );
    }
}
