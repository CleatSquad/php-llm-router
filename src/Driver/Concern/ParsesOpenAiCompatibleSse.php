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
 */
trait ParsesOpenAiCompatibleSse
{
    /**
     * @return Generator<int, string, mixed, void>
     */
    private static function readOpenAiCompatibleSse(StreamInterface $body): Generator
    {
        $buffer = '';
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
                    return;
                }

                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }

                $delta = $data['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    yield $delta;
                }
            }
        }
    }
}
