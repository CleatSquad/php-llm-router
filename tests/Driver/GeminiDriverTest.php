<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\GeminiDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GeminiDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithMockedResponses(array $responses, array &$history = []): GeminiDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
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
        ], model: 'gemini-2.5-flash'));

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

    public function testStreamCapturesTerminalUsageMetadataAndCost(): void
    {
        $sse = 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Bonjour']]]]]], JSON_THROW_ON_ERROR) . "\n\n"
            . 'data: ' . json_encode([
                'candidates' => [['content' => ['parts' => []], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ], JSON_THROW_ON_ERROR) . "\n\n";

        $driver = $this->driverWithMockedResponses([new Response(200, [], $sse)]);

        $gen = $driver->stream(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gemini-2.5-flash'
        ));
        $chunks = iterator_to_array($gen);
        $return = $gen->getReturn();

        $this->assertSame(['Bonjour'], $chunks);
        $this->assertIsArray($return);
        $this->assertNull($return['tool_calls']);
        $this->assertSame(10, $return['prompt_tokens']);
        $this->assertSame(5, $return['completion_tokens']);
        $this->assertSame(15, $return['total_tokens']);
        // (10 * 0.0003 + 5 * 0.0025) / 1000
        $this->assertEqualsWithDelta(0.0000155, $return['cost_usd'], 1e-9);
    }

    /**
     * Real bug fixed 2026-08-01: splitSystemAndConvert() used to do
     * `(string) ($message['content'] ?? '')`, which silently casts a
     * multi-part vision array to the literal string "Array", dropping the
     * image entirely, despite supportsVision() claiming otherwise. Asserts
     * the actual outgoing request body uses Gemini's native
     * {inlineData: {mimeType, data}} part instead.
     */
    public function testChatTranslatesVisionContentToGeminiInlineDataParts(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'Je vois une photo.']]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $driver->chat(new LLMRequest(messages: [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Que vois-tu ?'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,ZmFrZWRhdGE=']],
            ]],
        ], model: 'gemini-2.5-flash'));

        $sentBody = json_decode((string) $history[0]['request']->getBody(), true);
        $parts = $sentBody['contents'][0]['parts'];

        $this->assertSame('Que vois-tu ?', $parts[0]['text']);
        $this->assertSame('image/jpeg', $parts[1]['inlineData']['mimeType']);
        $this->assertSame('ZmFrZWRhdGE=', $parts[1]['inlineData']['data']);
    }

    public function testChatKeepsPlainTextContentUnchanged(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $driver->chat(new LLMRequest(messages: [
            ['role' => 'user', 'content' => 'Un message texte normal'],
        ], model: 'gemini-2.5-flash'));

        $sentBody = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('Un message texte normal', $sentBody['contents'][0]['parts'][0]['text']);
    }
    /**
     * RFC-0070, I-5 / criterion 7 — the key travels in a header, never in the
     * URL. Guzzle quotes the whole URL in its exception messages, and
     * PlanExecutor journals that message: a key in the query string ended up
     * in `docker logs concio-api` in clear, dozens of times on 2026-08-16.
     */
    public function testTheApiKeyTravelsInAHeaderAndNeverInTheUrl(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $driver->chat(new LLMRequest(messages: [
            ['role' => 'user', 'content' => 'Salut'],
        ], model: 'gemini-2.5-flash'));

        $request = $history[0]['request'];

        $this->assertSame('test-key', $request->getHeaderLine('x-goog-api-key'));
        $this->assertStringNotContainsString('key=', (string) $request->getUri());
        $this->assertStringNotContainsString('test-key', (string) $request->getUri());
    }
}
