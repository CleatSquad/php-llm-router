<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver\Concern;

use CleatSquad\LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use Generator;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * Invariant protected: fragmented tool calls arriving over SSE are accumulated
 *   by index and reconstructed whole, whatever order the fragments arrive in
 *   and however the stream ends.
 * Bug covered: none currently open — this pins the existing accumulator, which
 *   is the piece most likely to be "simplified" into losing fragments.
 * Type: characterisation.
 */
final class ParsesChatCompletionSseToolCallTest extends TestCase
{
    private function reader(): object
    {
        return new class {
            use ParsesChatCompletionSse;

            public function read(string $sse): Generator
            {
                return self::readChatCompletionSse(Utils::streamFor($sse));
            }
        };
    }

    /**
     * @param array<string, mixed> $delta
     */
    private static function sseLine(array $delta): string
    {
        return 'data: ' . json_encode(['choices' => [['delta' => $delta]]], JSON_THROW_ON_ERROR) . "\n\n";
    }

    /**
     * @return array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null
     */
    private function toolCallsOf(string $sse): ?array
    {
        $gen = $this->reader()->read($sse);
        iterator_to_array($gen);

        return $gen->getReturn();
    }

    public function testFragmentsArrivingOutOfOrderStillLandOnTheRightCall(): void
    {
        // Index 1 opens before index 0 and they interleave from there — legal
        // per the wire format, and the reason accumulation is keyed by index
        // rather than by arrival order.
        $sse = self::sseLine(['tool_calls' => [['index' => 1, 'id' => 'call_b', 'function' => ['name' => 'send_']]]])
            . self::sseLine(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'get_']]]])
            . self::sseLine(['tool_calls' => [['index' => 1, 'function' => ['name' => 'mail']]]])
            . self::sseLine(['tool_calls' => [['index' => 0, 'function' => ['name' => 'weather']]]])
            . "data: [DONE]\n\n";

        $toolCalls = $this->toolCallsOf($sse);

        $this->assertNotNull($toolCalls);
        $this->assertCount(2, $toolCalls);
        $this->assertSame('call_a', $toolCalls[0]['id']);
        $this->assertSame('get_weather', $toolCalls[0]['function']['name']);
        $this->assertSame('call_b', $toolCalls[1]['id']);
        $this->assertSame('send_mail', $toolCalls[1]['function']['name']);
    }

    public function testManyArgumentFragmentsForOneIndexConcatenateInArrivalOrder(): void
    {
        $sse = self::sseLine(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '']]]]);
        foreach (['{"ci', 'ty":"Pa', 'ris","un', 'its":"c"}'] as $fragment) {
            $sse .= self::sseLine(['tool_calls' => [['index' => 0, 'function' => ['arguments' => $fragment]]]]);
        }
        $sse .= "data: [DONE]\n\n";

        $toolCalls = $this->toolCallsOf($sse);

        $this->assertNotNull($toolCalls);
        $this->assertSame('{"city":"Paris","units":"c"}', $toolCalls[0]['function']['arguments']);
        $this->assertSame(
            ['city' => 'Paris', 'units' => 'c'],
            json_decode($toolCalls[0]['function']['arguments'], true),
            'the reassembled arguments must be valid JSON, not a mangled prefix'
        );
    }

    public function testAStreamCutShortBeforeDoneStillReturnsWhatWasAccumulated(): void
    {
        // No "data: [DONE]" line: the provider dropped the connection. What
        // was accumulated so far is still returned rather than thrown away.
        $sse = self::sseLine(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":']]]])
            . self::sseLine(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"Paris"}']]]]);

        $toolCalls = $this->toolCallsOf($sse);

        $this->assertNotNull($toolCalls);
        $this->assertSame('{"city":"Paris"}', $toolCalls[0]['function']['arguments']);
    }

    public function testAStreamOfNothingButToolCallsYieldsNoTextButReturnsTheCalls(): void
    {
        $sse = self::sseLine(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'lookup', 'arguments' => '{}']]]])
            . "data: [DONE]\n\n";

        $gen = $this->reader()->read($sse);
        $chunks = iterator_to_array($gen);

        $this->assertSame([], $chunks, 'a tool-call-only turn has no visible text');
        $this->assertNotNull($gen->getReturn());
        $this->assertSame('lookup', $gen->getReturn()[0]['function']['name']);
    }

    public function testInterleavedTextAndToolCallsKeepBothIntact(): void
    {
        $sse = self::sseLine(['content' => 'Let me check. '])
            . self::sseLine(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_', 'arguments' => '{"a"']]]])
            . self::sseLine(['content' => 'One moment.'])
            . self::sseLine(['tool_calls' => [['index' => 0, 'function' => ['name' => 'weather', 'arguments' => ':1}']]]])
            . "data: [DONE]\n\n";

        $gen = $this->reader()->read($sse);
        $chunks = iterator_to_array($gen);

        $this->assertSame(['Let me check. ', 'One moment.'], $chunks);
        $this->assertSame('get_weather', $gen->getReturn()[0]['function']['name']);
        $this->assertSame('{"a":1}', $gen->getReturn()[0]['function']['arguments']);
    }

    public function testAToolCallDeltaWithoutAnIndexFallsOnIndexZero(): void
    {
        $sse = self::sseLine(['tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'run', 'arguments' => '{']]]])
            . self::sseLine(['tool_calls' => [['function' => ['arguments' => '}']]]])
            . "data: [DONE]\n\n";

        $toolCalls = $this->toolCallsOf($sse);

        $this->assertNotNull($toolCalls);
        $this->assertCount(1, $toolCalls, 'index-less deltas must merge, not spawn a call each');
        $this->assertSame('{}', $toolCalls[0]['function']['arguments']);
    }

    public function testAnUnparseableDataLineIsSkippedWithoutLosingSurroundingFragments(): void
    {
        $sse = self::sseLine(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'run', 'arguments' => '{"a"']]]])
            . "data: {not json}\n\n"
            . self::sseLine(['tool_calls' => [['index' => 0, 'function' => ['arguments' => ':1}']]]])
            . "data: [DONE]\n\n";

        $toolCalls = $this->toolCallsOf($sse);

        $this->assertNotNull($toolCalls);
        $this->assertSame('{"a":1}', $toolCalls[0]['function']['arguments']);
    }

    public function testATextOnlyStreamReturnsNoToolCalls(): void
    {
        $this->assertNull($this->toolCallsOf(self::sseLine(['content' => 'hello']) . "data: [DONE]\n\n"));
    }
}
