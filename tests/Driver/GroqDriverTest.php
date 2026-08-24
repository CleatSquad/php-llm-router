<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\GroqDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GroqDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string $groqApiKey = 'test-key'): GroqDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new GroqDriver(new HttpClient($client), groqApiKey: $groqApiKey);
    }

    private function chatResponse(string $content = 'Bonjour !', string $model = 'llama-3.1-8b-instant'): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'model' => $model,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatMapsResponseAndComputesCostFromPricingTable(): void
    {
        $driver = $this->driverWithMockedResponses([$this->chatResponse('Bonjour !', 'llama-3.3-70b-versatile')]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            model: 'llama-3.3-70b-versatile'
        ));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        $this->assertSame('stop', $response->finishReason);
        // (10 * 0.00059 + 5 * 0.00079) / 1000
        $this->assertEqualsWithDelta(0.00000985, $response->costUsd, 1e-9);
    }

    public function testChatSendsBearerAuthorizationHeader(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, groqApiKey: 'secret-key');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'Salut']]));

        $this->assertSame('Bearer secret-key', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testChatThrowsOnApiErrorEnvelope(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'error' => ['message' => 'Invalid API Key'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Groq API error: Invalid API Key');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatWrapsTransportFailureAsARequestFailure(): void
    {
        $driver = $this->driverWithMockedResponses([new Response(500, [], 'boom')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Groq request failed');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('groq', $driver->getId());
        $this->assertSame('Groq Direct', $driver->getName());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableReflectsApiKeyPresence(): void
    {
        $withKey = $this->driverWithMockedResponses([], groqApiKey: 'test-key');
        $withoutKey = $this->driverWithMockedResponses([], groqApiKey: '');

        $this->assertTrue($withKey->isAvailable());
        $this->assertFalse($withoutKey->isAvailable());
    }

    public function testCapabilityFlags(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertTrue($driver->supportsStreaming());
        $this->assertTrue($driver->supportsTools());
        $this->assertFalse($driver->supportsVision());
        // Was false until this driver learned to express a reasoning request.
        // It reports what the *driver* can translate, not what the model you
        // picked will accept — see "Reasoning" in the README for which models
        // actually honour it.
        $this->assertTrue($driver->supportsReasoning());
    }

    public function testEstimateCostUsesPricingForResolvedModelAndDefaultOutputBudget(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        // 16 chars => ceil(16/4) = 4 input tokens.
        $request = new LLMRequest(
            messages: [['role' => 'user', 'content' => 'a message of 16c']],
            model: 'llama-3.3-70b-versatile'
        );

        $estimate = $driver->estimateCost($request);

        $this->assertSame(0.00059, $estimate->inputCostPer1k);
        $this->assertSame(0.00079, $estimate->outputCostPer1k);
        $this->assertSame(4 + 200, $estimate->estimatedTokens);
        $expectedCost = ((4 * 0.00059) + (200 * 0.00079)) / 1000;
        $this->assertEqualsWithDelta($expectedCost, $estimate->estimatedCostUsd, 1e-9);
    }

    public function testStreamSendsIncludeUsageOptionAndCapturesTerminalUsageAndCost(): void
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
        ], $history);

        $gen = $driver->stream(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            model: 'llama-3.1-8b-instant'
        ));

        $chunks = iterator_to_array($gen);
        $return = $gen->getReturn();

        $this->assertSame(['Bonjour'], $chunks);
        $this->assertIsArray($return);
        $this->assertNull($return['tool_calls']);
        $this->assertSame(10, $return['prompt_tokens']);
        $this->assertSame(5, $return['completion_tokens']);
        $this->assertSame(15, $return['total_tokens']);
        // (10 * 0.00005 + 5 * 0.00008) / 1000
        $this->assertEqualsWithDelta(0.0000009, $return['cost_usd'], 1e-9);

        $requestPayload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertTrue($requestPayload['stream_options']['include_usage'] ?? false);
    }

    public function testGetModelsReturnsPricingTableKeys(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame(
            [
                'qwen/qwen3.6-27b',
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
                'llama-3.3-70b-versatile',
                'llama-3.1-8b-instant',
            ],
            $driver->getModels()
        );
    }
}
