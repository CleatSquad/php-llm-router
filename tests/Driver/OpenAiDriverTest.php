<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\OpenAiDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpenAiDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string $openAiApiKey = 'test-key'): OpenAiDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new OpenAiDriver(new HttpClient($client), openAiApiKey: $openAiApiKey);
    }

    private function chatResponse(string $content = 'Bonjour !', string $model = 'gpt-4o-mini'): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'model' => $model,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatMapsResponseAndComputesCostFromPricingTable(): void
    {
        $driver = $this->driverWithMockedResponses([$this->chatResponse('Bonjour !', 'gpt-4o')]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            model: 'gpt-4o'
        ));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        // (10 * 0.0025 + 5 * 0.01) / 1000
        $this->assertEqualsWithDelta(0.000075, $response->costUsd, 1e-9);
    }

    public function testChatSendsBearerAuthorizationHeader(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, openAiApiKey: 'secret-key');

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
        $this->expectExceptionMessage('OpenAI API error: Invalid API Key');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatWrapsTransportFailureAsARequestFailure(): void
    {
        $driver = $this->driverWithMockedResponses([new Response(500, [], 'boom')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI request failed');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('openai', $driver->getId());
        $this->assertSame('OpenAI Direct (ChatGPT)', $driver->getName());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableReflectsApiKeyPresence(): void
    {
        $withKey = $this->driverWithMockedResponses([], openAiApiKey: 'test-key');
        $withoutKey = $this->driverWithMockedResponses([], openAiApiKey: '');

        $this->assertTrue($withKey->isAvailable());
        $this->assertFalse($withoutKey->isAvailable());
    }

    public function testCapabilityFlags(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertTrue($driver->supportsStreaming());
        $this->assertTrue($driver->supportsTools());
        $this->assertTrue($driver->supportsVision());
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
            model: 'gpt-4o'
        );

        $estimate = $driver->estimateCost($request);

        $this->assertSame(0.0025, $estimate->inputCostPer1k);
        $this->assertSame(0.01, $estimate->outputCostPer1k);
        $this->assertSame(4 + 200, $estimate->estimatedTokens);
        $expectedCost = ((4 * 0.0025) + (200 * 0.01)) / 1000;
        $this->assertEqualsWithDelta($expectedCost, $estimate->estimatedCostUsd, 1e-9);
    }

    public function testGetModelsReturnsPricingTableKeys(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $models = $driver->getModels();

        // The catalogue leads with the current families and keeps the gpt-4o
        // pair, which is still served and still the default.
        $this->assertContains('gpt-5', $models);
        $this->assertContains('o3', $models);
        $this->assertContains('gpt-4o', $models);
        $this->assertContains('gpt-4o-mini', $models);
    }
}
