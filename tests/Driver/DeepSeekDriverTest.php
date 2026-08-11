<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\DeepSeekDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class DeepSeekDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string $deepSeekApiKey = 'test-key'): DeepSeekDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new DeepSeekDriver(new HttpClient($client), deepSeekApiKey: $deepSeekApiKey);
    }

    private function chatResponse(string $content = 'Bonjour !', string $model = 'deepseek-v4-flash'): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'model' => $model,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatMapsResponseAndComputesCostFromPricingTable(): void
    {
        $driver = $this->driverWithMockedResponses([$this->chatResponse('Bonjour !', 'deepseek-v4-pro')]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            model: 'deepseek-v4-pro'
        ));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        // (10 * 0.000435 + 5 * 0.00087) / 1000
        $this->assertEqualsWithDelta(0.0000087, $response->costUsd, 1e-9);
    }

    public function testChatSendsBearerAuthorizationHeader(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, deepSeekApiKey: 'secret-key');

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
        $this->expectExceptionMessage('DeepSeek API error: Invalid API Key');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatWrapsTransportFailureAsARequestFailure(): void
    {
        $driver = $this->driverWithMockedResponses([new Response(500, [], 'boom')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DeepSeek request failed');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('deepseek', $driver->getId());
        $this->assertSame('DeepSeek Direct', $driver->getName());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableReflectsApiKeyPresence(): void
    {
        $withKey = $this->driverWithMockedResponses([], deepSeekApiKey: 'test-key');
        $withoutKey = $this->driverWithMockedResponses([], deepSeekApiKey: '');

        $this->assertTrue($withKey->isAvailable());
        $this->assertFalse($withoutKey->isAvailable());
    }

    public function testCapabilityFlags(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertTrue($driver->supportsStreaming());
        $this->assertTrue($driver->supportsTools());
        $this->assertFalse($driver->supportsVision());
        // DeepSeek is the only OpenAI-compatible direct driver in this set
        // that flags reasoning support (deepseek-v4-pro).
        $this->assertTrue($driver->supportsReasoning());
    }

    public function testEstimateCostUsesPricingForResolvedModelAndDefaultOutputBudget(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        // 16 chars => ceil(16/4) = 4 input tokens.
        $request = new LLMRequest(
            messages: [['role' => 'user', 'content' => 'a message of 16c']],
            model: 'deepseek-v4-pro'
        );

        $estimate = $driver->estimateCost($request);

        $this->assertSame(0.000435, $estimate->inputCostPer1k);
        $this->assertSame(0.00087, $estimate->outputCostPer1k);
        $this->assertSame(4 + 200, $estimate->estimatedTokens);
        $expectedCost = ((4 * 0.000435) + (200 * 0.00087)) / 1000;
        $this->assertEqualsWithDelta($expectedCost, $estimate->estimatedCostUsd, 1e-9);
    }

    public function testGetModelsReturnsPricingTableKeys(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame(['deepseek-v4-flash', 'deepseek-v4-pro'], $driver->getModels());
    }
}
