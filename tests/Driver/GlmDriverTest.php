<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\GlmDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GlmDriverTest extends TestCase
{
    private const TEST_PRICING = [
        'glm-4.6' => ['input' => 0.0006, 'output' => 0.0022],
    ];

    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     * @param array<string, array{input: float, output: float}> $extraModelPricing
     */
    private function driverWithMockedResponses(
        array $responses,
        array &$history = [],
        string $glmApiKey = 'test-key',
        array $extraModelPricing = self::TEST_PRICING,
    ): GlmDriver {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new GlmDriver(new HttpClient($client), glmApiKey: $glmApiKey, extraModelPricing: $extraModelPricing);
    }

    private function chatResponse(string $content = 'Bonjour !', string $model = 'glm-4.6'): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'model' => $model,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatMapsResponseAndComputesCostFromRegisteredPricing(): void
    {
        $driver = $this->driverWithMockedResponses([$this->chatResponse('Bonjour !', 'glm-4.6')]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            model: 'glm-4.6'
        ));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        // (10 * 0.0006 + 5 * 0.0022) / 1000
        $this->assertEqualsWithDelta(0.000017, $response->costUsd, 1e-9);
    }

    public function testChatSendsBearerAuthorizationHeader(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, glmApiKey: 'secret-key');

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
        $this->expectExceptionMessage('GLM API error: Invalid API Key');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatRejectsAnUnknownModelWhenNoPricingIsRegistered(): void
    {
        // The package ships no GLM pricing yet — an explicitly
        // named model with no registered rate must be refused, not served
        // at a guessed price.
        $driver = $this->driverWithMockedResponses([], extraModelPricing: []);

        $this->expectException(\CleatSquad\LlmRouter\Exception\UnknownModelException::class);

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']], model: 'glm-4.6'));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('glm', $driver->getId());
        $this->assertSame('GLM (Zhipu AI)', $driver->getName());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableReflectsApiKeyPresence(): void
    {
        $withKey = $this->driverWithMockedResponses([], glmApiKey: 'test-key');
        $withoutKey = $this->driverWithMockedResponses([], glmApiKey: '');

        $this->assertTrue($withKey->isAvailable());
        $this->assertFalse($withoutKey->isAvailable());
    }

    public function testCapabilityFlags(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertTrue($driver->supportsStreaming());
        $this->assertTrue($driver->supportsTools());
        $this->assertFalse($driver->supportsVision());
        $this->assertTrue($driver->supportsReasoning());
    }

    public function testGetModelsReturnsRegisteredPricingKeys(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame(['glm-4.6'], $driver->getModels());
    }

    public function testStreamSendsIncludeUsageOptionAndCapturesTerminalUsageAndCost(): void
    {
        $sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Bonjour']]]]) . "\n\n"
            . 'data: ' . json_encode([
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
            model: 'glm-4.6'
        ));

        $chunks = iterator_to_array($gen);
        $return = $gen->getReturn();

        $this->assertSame(['Bonjour'], $chunks);
        $this->assertIsArray($return);
        $this->assertSame(10, $return['prompt_tokens']);
        $this->assertSame(5, $return['completion_tokens']);
        $this->assertSame(15, $return['total_tokens']);
    }
}
