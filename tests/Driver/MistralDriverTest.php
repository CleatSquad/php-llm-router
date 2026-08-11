<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\MistralDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class MistralDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string $mistralApiKey = 'test-key'): MistralDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new MistralDriver(new HttpClient($client), mistralApiKey: $mistralApiKey);
    }

    private function chatResponse(string $content = 'Bonjour !', string $model = 'mistral-small-latest'): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'model' => $model,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatMapsResponseAndComputesCostFromPricingTable(): void
    {
        $driver = $this->driverWithMockedResponses([$this->chatResponse('Bonjour !', 'mistral-large-latest')]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            model: 'mistral-large-latest'
        ));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        // (10 * 0.002 + 5 * 0.006) / 1000
        $this->assertEqualsWithDelta(0.00005, $response->costUsd, 1e-9);
    }

    public function testChatSendsBearerAuthorizationHeader(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, mistralApiKey: 'secret-key');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'Salut']]));

        $this->assertSame('Bearer secret-key', $history[0]['request']->getHeaderLine('Authorization'));
    }

    /**
     * Mistral's error envelope is a bare top-level {"message": "..."} with
     * no "choices" key, unlike the {"error": {"message": ...}} shape used
     * by Groq/OpenAI/DeepSeek.
     */
    public function testChatThrowsOnApiErrorEnvelope(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'message' => 'Unauthorized',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mistral API error: Unauthorized');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatWrapsTransportFailureAsARequestFailure(): void
    {
        $driver = $this->driverWithMockedResponses([new Response(500, [], 'boom')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mistral request failed');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('mistral', $driver->getId());
        $this->assertSame('Mistral AI Direct', $driver->getName());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableReflectsApiKeyPresence(): void
    {
        $withKey = $this->driverWithMockedResponses([], mistralApiKey: 'test-key');
        $withoutKey = $this->driverWithMockedResponses([], mistralApiKey: '');

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
            model: 'codestral-latest'
        );

        $estimate = $driver->estimateCost($request);

        $this->assertSame(0.0003, $estimate->inputCostPer1k);
        $this->assertSame(0.0009, $estimate->outputCostPer1k);
        $this->assertSame(4 + 200, $estimate->estimatedTokens);
        $expectedCost = ((4 * 0.0003) + (200 * 0.0009)) / 1000;
        $this->assertEqualsWithDelta($expectedCost, $estimate->estimatedCostUsd, 1e-9);
    }

    public function testGetModelsReturnsPricingTableKeys(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame(
            ['mistral-large-latest', 'mistral-small-latest', 'codestral-latest', 'open-mistral-nemo'],
            $driver->getModels()
        );
    }
}
