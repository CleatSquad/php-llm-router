<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\GeminiDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class GeminiDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses): GeminiDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handlerStack]);

        return new GeminiDriver(new HttpClient($client), geminiApiKey: 'test-key');
    }

    public function testChatMapsSystemAndAssistantRolesAndExtractsUsage(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Bonjour !']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->chat(new LLMRequest(messages: [
            ['role' => 'system', 'content' => 'Tu es un assistant.'],
            ['role' => 'user', 'content' => 'Salut'],
            ['role' => 'assistant', 'content' => 'Bonjour, comment puis-je aider ?'],
            ['role' => 'user', 'content' => 'Dis bonjour'],
        ], model: 'gemini-2.0-flash'));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        $this->assertNull($response->toolCalls);
        $this->assertGreaterThan(0.0, $response->costUsd);
    }

    public function testChatExtractsAFunctionCallAsAToolCall(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Paris']],
                    ]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8, 'totalTokenCount' => 28],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Meteo a Paris ?']],
            tools: [[
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => ['type' => 'object']],
            ]]
        ));

        $this->assertSame('', $response->content);
        $this->assertNotNull($response->toolCalls);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('get_weather', $response->toolCalls[0]['function']['name']);
        $this->assertSame('{"city":"Paris"}', $response->toolCalls[0]['function']['arguments']);
    }

    public function testChatThrowsOnApiErrorEnvelopeEvenWithA200Status(): void
    {
        // Exercises the isset($data['error']) guard directly, independent
        // of Guzzle's own throw-on-4xx behavior (which the request-failed
        // wrapper below already covers for real non-2xx responses).
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'error' => ['message' => 'API key not valid'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini API error: API key not valid');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatWrapsNon2xxResponsesAsARequestFailure(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(400, [], json_encode([
                'error' => ['message' => 'API key not valid'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini request failed');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testStreamYieldsTextChunksAndReturnsToolCalls(): void
    {
        $sse = 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Bon']]]]]], JSON_THROW_ON_ERROR) . "\n\n"
            . 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'jour']]]]]], JSON_THROW_ON_ERROR) . "\n\n"
            . 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [[
                'functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Paris']],
            ]]]]]], JSON_THROW_ON_ERROR) . "\n\n";

        $driver = $this->driverWithMockedResponses([new Response(200, [], $sse)]);

        $gen = $driver->stream(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
        $chunks = iterator_to_array($gen);
        $toolCalls = $gen->getReturn();

        $this->assertSame(['Bon', 'jour'], $chunks);
        $this->assertCount(1, $toolCalls);
        $this->assertSame('get_weather', $toolCalls[0]['function']['name']);
        $this->assertSame('{"city":"Paris"}', $toolCalls[0]['function']['arguments']);
    }
}
