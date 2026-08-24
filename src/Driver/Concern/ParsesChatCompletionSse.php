<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver\Concern;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Shared by drivers using the OpenAI Chat Completions SSE format (LiteLLM, OpenAI, Kimi/Moonshot): "data: {json}" lines ending in "data: [DONE]".
 * Streamed tool_calls deltas are accumulated by index, since each call's id/name/arguments arrive fragmented across multiple deltas.
 *
 * Reasoning deltas (DeepSeek and Kimi call the field `reasoning_content`, Groq
 * calls it `reasoning`) are handed to the request's onReasoning callback rather
 * than yielded: the generator's values are the visible answer, and an
 * application echoing them must not print the model's scratch work to a user.
 */
trait ParsesChatCompletionSse
{
    /**
     * @return Generator<int, string, mixed, (array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|array{tool_calls: array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}|null)>
     */
    private static function readChatCompletionSse(StreamInterface $body, ?LLMRequest $request = null): Generator
    {
        $buffer = '';
        $toolCalls = [];
        $usage = null;

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === '[DONE]') {
                    return self::finishStreamResult($toolCalls, $usage);
                }

                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }

                if (isset($data['usage']) && is_array($data['usage'])) {
                    $usage = [
                        'prompt_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
                        'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
                        'total_tokens' => (int) ($data['usage']['total_tokens'] ?? 0),
                    ];
                }

                $delta = $data['choices'][0]['delta'] ?? [];

                $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? '';
                if (is_string($reasoning) && $reasoning !== '') {
                    $request?->emitReasoning($reasoning);
                }

                $content = $delta['content'] ?? '';
                if ($content !== '') {
                    yield $content;
                }

                foreach ($delta['tool_calls'] ?? [] as $tcDelta) {
                    $index = $tcDelta['index'] ?? 0;
                    if (!isset($toolCalls[$index])) {
                        $toolCalls[$index] = [
                            'id' => '',
                            'type' => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    if (isset($tcDelta['id'])) {
                        $toolCalls[$index]['id'] = $tcDelta['id'];
                    }
                    if (isset($tcDelta['function']['name'])) {
                        $toolCalls[$index]['function']['name'] .= $tcDelta['function']['name'];
                    }
                    if (isset($tcDelta['function']['arguments'])) {
                        $toolCalls[$index]['function']['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }
        }

        return self::finishStreamResult($toolCalls, $usage);
    }

    /**
     * @param array<int, array{id: string, type: string, function: array{name: string, arguments: string}}> $toolCalls
     * @param array{prompt_tokens: int, completion_tokens: int, total_tokens: int}|null $usage
     * @return array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|array{tool_calls: array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}|null
     */
    private static function finishStreamResult(array $toolCalls, ?array $usage): ?array
    {
        $finishedToolCalls = self::finishToolCalls($toolCalls);
        if ($usage === null) {
            return $finishedToolCalls;
        }

        return [
            'tool_calls' => $finishedToolCalls,
            'usage' => $usage,
        ];
    }

    /**
     * Flattens the accumulator into the returned list.
     *
     * Sorted by index, not by arrival: a provider is free to open call #1
     * before call #0, and array_values() alone would then hand the caller the
     * calls in arrival order, silently swapping which id and which arguments
     * belong to which position.
     *
     * @param array<int, array{id: string, type: string, function: array{name: string, arguments: string}}> $toolCalls
     * @return array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null
     */
    private static function finishToolCalls(array $toolCalls): ?array
    {
        if ($toolCalls === []) {
            return null;
        }

        ksort($toolCalls);

        return array_values($toolCalls);
    }
}
