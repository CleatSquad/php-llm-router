<?php

declare(strict_types=1);

namespace LlmRouter\Driver\Concern;

use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Shared by every driver whose provider speaks the OpenAI Chat Completions
 * streaming wire format (LiteLLM, OpenAI, Kimi/Moonshot): SSE lines of
 * "data: {json}\n\n", each carrying a choices[0].delta.content fragment,
 * terminated by a literal "data: [DONE]" line.
 *
 * Also accumulates choices[0].delta.tool_calls — a streamed tool call
 * arrives as a first delta carrying {index, id, function: {name}} followed
 * by further deltas for the same index carrying only
 * {index, function: {arguments}} fragments (the JSON args string arrives
 * character-by-character-ish, not as one block) — grouped by index so two
 * tool calls in the same response don't interleave into garbage.
 */
trait ParsesChatCompletionSse
{
    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    private static function readChatCompletionSse(StreamInterface $body): Generator
    {
        $buffer = '';
        $toolCalls = [];

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
                    return $toolCalls === [] ? null : array_values($toolCalls);
                }

                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }

                $delta = $data['choices'][0]['delta'] ?? [];

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

        return $toolCalls === [] ? null : array_values($toolCalls);
    }
}
