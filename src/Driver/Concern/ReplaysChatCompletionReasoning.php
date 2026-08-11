<?php

declare(strict_types=1);

namespace LlmRouter\Driver\Concern;

/**
 * Rewrites the history for providers that speak the OpenAI Chat Completions
 * message shape.
 *
 * LLMResponse::toMessage() carries a reasoning trace under the neutral key
 * `reasoning`, because callers should not have to know that DeepSeek, Kimi and
 * Mistral call it `reasoning_content` while Groq calls it `reasoning`. This
 * translates it back on the way out.
 *
 * Replaying matters: Moonshot documents that dropping the trace during a
 * tool-calling loop degrades the model, and Mistral says the same of its think
 * chunks. Silently discarding it would make multi-turn reasoning quietly worse
 * in a way nothing in the response reveals.
 */
trait ReplaysChatCompletionReasoning
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private static function withReplayedReasoning(array $messages, string $field = 'reasoning_content'): array
    {
        foreach ($messages as $i => $message) {
            $reasoning = $message['reasoning'] ?? null;

            if (($message['role'] ?? null) !== 'assistant' || !is_string($reasoning) || $reasoning === '') {
                continue;
            }

            unset($messages[$i]['reasoning'], $messages[$i]['reasoning_signature']);
            $messages[$i][$field] = $reasoning;
        }

        return $messages;
    }

    /**
     * Drops the neutral reasoning keys without translating them, for providers
     * that never return a trace and reject unknown message fields.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private static function withoutReasoningKeys(array $messages): array
    {
        foreach ($messages as $i => $message) {
            unset($messages[$i]['reasoning'], $messages[$i]['reasoning_signature']);
        }

        return $messages;
    }
}
