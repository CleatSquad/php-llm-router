<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Enum;

/**
 * How hard the model should think before answering, in terms every provider
 * can honour rather than in one provider's units.
 *
 * Effort — not a token budget — is the portable abstraction. OpenAI, DeepSeek
 * and Groq all spell it `reasoning_effort`; Anthropic spells it
 * `output_config.effort`; Ollama's `think` takes these very level names. A
 * thinking *budget* only survives in Anthropic's legacy manual mode, which is
 * deprecated on Claude 4.6 and returns a 400 on Claude 4.7 and later, so a
 * budget-shaped API would have been born broken on current models.
 *
 * Providers accept different subsets. Each driver clamps to what its API
 * takes and documents the mapping; nothing is silently dropped.
 */
enum ReasoningEffort: string
{
    /** Answer without thinking, where the provider allows turning it off. */
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    /**
     * Ordering used when a provider supports fewer levels than this enum, so
     * asking for `max` where the ceiling is `high` lands on `high` rather than
     * being dropped or rejected.
     */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::XHigh => 4,
            self::Max => 5,
        };
    }

    /**
     * Clamps to the nearest level a driver actually accepts: the highest
     * supported level at or below this one, or the cheapest supported level
     * when this one sits below the provider's floor.
     *
     * @param non-empty-array<int, self> $supported
     */
    public function clampTo(array $supported): self
    {
        $best = null;
        $cheapest = null;

        foreach ($supported as $level) {
            if ($level === $this) {
                return $this;
            }
            if ($level->rank() <= $this->rank() && ($best === null || $level->rank() > $best->rank())) {
                $best = $level;
            }
            if ($cheapest === null || $level->rank() < $cheapest->rank()) {
                $cheapest = $level;
            }
        }

        return $best ?? $cheapest;
    }
}
