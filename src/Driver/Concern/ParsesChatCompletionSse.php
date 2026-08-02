<?php

declare(strict_types=1);

namespace LlmRouter\Driver\Concern;

use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Shared by drivers using the OpenAI Chat Completions SSE format (LiteLLM, OpenAI, Kimi/Moonshot): "data: {json}" lines ending in "data: [DONE]".
 * Streamed tool_calls deltas are accumulated by index, since each call's id/name/arguments arrive fragmented across multiple deltas.
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
