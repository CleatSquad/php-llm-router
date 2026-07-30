<?php

declare(strict_types=1);

namespace Concio\LlmRouter\Tests\Driver\Concern;

use GuzzleHttp\Psr7\Utils;
use LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use PHPUnit\Framework\TestCase;

final class ParsesChatCompletionSseTest extends TestCase
{
    private function reader(): object
    {
        return new class {
            use ParsesChatCompletionSse;

            public function read(string $sse): \Generator
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
        $payload = ['choices' => [['delta' => $delta]]];
        return 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
    }

    public function testYieldsTextContentFragments(): void
    {
        $sse = self::sseLine(['content' => 'Hel'])
            . self::sseLine(['content' => 'lo'])
            . "data: [DONE]\n\n";

        $gen = $this->reader()->read($sse);
        $chunks = iterator_to_array($gen);

        $this->assertSame(['Hel', 'lo'], $chunks);
        $this->assertNull($gen->getReturn());
    }

    public function testAccumulatesASingleToolCallSplitAcrossDeltas(): void
    {
        $sse = self::sseLine(['tool_calls' => [
                ['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '']],
            ]])
            . self::sseLine(['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"city":']],
            ]])
            . self::sseLine(['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '"Paris"}']],
            ]])
            . "data: [DONE]\n\n";

        $gen = $this->reader()->read($sse);
        $chunks = iterator_to_array($gen);
        $toolCalls = $gen->getReturn();

        $this->assertSame([], $chunks);
        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_1', $toolCalls[0]['id']);
        $this->assertSame('function', $toolCalls[0]['type']);
        $this->assertSame('get_weather', $toolCalls[0]['function']['name']);
        $this->assertSame('{"city":"Paris"}', $toolCalls[0]['function']['arguments']);
    }

    public function testAccumulatesTwoToolCallsByIndexWithoutInterleaving(): void
    {
        $sse = self::sseLine(['tool_calls' => [
                ['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'tool_a', 'arguments' => '']],
            ]])
            . self::sseLine(['tool_calls' => [
                ['index' => 1, 'id' => 'call_b', 'function' => ['name' => 'tool_b', 'arguments' => '']],
            ]])
            . self::sseLine(['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"x":1}']],
            ]])
            . self::sseLine(['tool_calls' => [
                ['index' => 1, 'function' => ['arguments' => '{"y":2}']],
            ]])
            . "data: [DONE]\n\n";

        $toolCalls = $this->reader()->read($sse)->getReturn();

        $this->assertCount(2, $toolCalls);
        $this->assertSame('tool_a', $toolCalls[0]['function']['name']);
        $this->assertSame('{"x":1}', $toolCalls[0]['function']['arguments']);
        $this->assertSame('tool_b', $toolCalls[1]['function']['name']);
        $this->assertSame('{"y":2}', $toolCalls[1]['function']['arguments']);
    }

    public function testReturnsNullWhenNoToolCallsOccurred(): void
    {
        $sse = self::sseLine(['content' => 'just text']) . "data: [DONE]\n\n";

        $gen = $this->reader()->read($sse);
        iterator_to_array($gen);

        $this->assertNull($gen->getReturn());
    }
}
