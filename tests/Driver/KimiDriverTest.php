<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\KimiDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class KimiDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string $moonshotModel = 'kimi-k2.6'): KimiDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new KimiDriver(new HttpClient($client), moonshotApiKey: 'test-key', moonshotModel: $moonshotModel);
    }

    private function chatResponse(): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'Bonjour !'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatForcesTemperatureToOneForK2ReasoningModels(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, moonshotModel: 'kimi-k2.6');

        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            temperature: 0.70
        ));

        $sentPayload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertEquals(1.0, $sentPayload['temperature']);
    }

    public function testChatKeepsTheRequestedTemperatureForNonK2Models(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, moonshotModel: 'kimi-k3');

        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            temperature: 0.70
        ));

        $sentPayload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(0.70, $sentPayload['temperature']);
    }

    public function testStreamForcesTemperatureToOneForK2ReasoningModels(): void
    {
        $sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Bonjour']]]], JSON_THROW_ON_ERROR) . "\n\n"
            . "data: [DONE]\n\n";

        $history = [];
        $driver = $this->driverWithMockedResponses([new Response(200, [], $sse)], $history, moonshotModel: 'kimi-k2.6');

        $gen = $driver->stream(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            temperature: 0.70
        ));
        iterator_to_array($gen);

        $sentPayload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertEquals(1.0, $sentPayload['temperature']);
    }

    public function testStreamSendsIncludeUsageOptionAndCapturesTerminalUsageAndEstimatedCost(): void
    {
        $sse = "data: " . json_encode(['choices' => [['delta' => ['content' => 'Bonjour']]]]) . "\n\n"
            . "data: " . json_encode([
                'choices' => [['delta' => []]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]) . "\n\n"
            . "data: [DONE]\n\n";

        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sse),
        ], $history, moonshotModel: 'kimi-k2.6');

        $gen = $driver->stream(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
        ));

        $chunks = iterator_to_array($gen);
        $return = $gen->getReturn();

        $this->assertSame(['Bonjour'], $chunks);
        $this->assertIsArray($return);
        $this->assertNull($return['tool_calls']);
        $this->assertSame(10, $return['prompt_tokens']);
        $this->assertSame(5, $return['completion_tokens']);
        $this->assertSame(15, $return['total_tokens']);
        // Moonshot has no cost in its usage payload — estimated from "Salut"
        // (5 chars => 2 input tokens) and the real completion token count.
        // (2 * 0.00095 + 5 * 0.004) / 1000
        $this->assertEqualsWithDelta(0.0000219, $return['cost_usd'], 1e-9);

        $requestPayload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertTrue($requestPayload['stream_options']['include_usage'] ?? false);
    }
}
