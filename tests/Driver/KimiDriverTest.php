<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\KimiDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class KimiDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string $moonshotModel = 'moonshot-v1-8k'): KimiDriver
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
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, moonshotModel: 'moonshot-v1-8k');

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
}
