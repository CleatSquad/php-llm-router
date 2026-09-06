<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\GlmImageDriver;
use CleatSquad\LlmRouter\DTO\ImageGenerationRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Exception\UnknownModelException;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GlmImageDriverTest extends TestCase
{
    private const TEST_PRICING = ['cogview-4' => 0.04];

    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     * @param array<string, float> $extraModelPricing
     */
    private function driverWithMockedResponses(
        array $responses,
        array &$history = [],
        string $glmApiKey = 'test-key',
        array $extraModelPricing = self::TEST_PRICING,
    ): GlmImageDriver {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new GlmImageDriver(new HttpClient($client), glmApiKey: $glmApiKey, extraModelPricing: $extraModelPricing);
    }

    public function testGenerateReturnsUrlsAndComputesCostFromRegisteredPricing(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'data' => [['url' => 'https://example.test/a.png'], ['url' => 'https://example.test/b.png']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->generate(new ImageGenerationRequest(prompt: 'a red cat', model: 'cogview-4', count: 2));

        $this->assertSame(['https://example.test/a.png', 'https://example.test/b.png'], $response->urls);
        $this->assertSame([], $response->base64Images);
        $this->assertEqualsWithDelta(0.08, $response->costUsd, 1e-9);
    }

    public function testGenerateSendsBearerAuthorizationHeader(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode(['data' => [['url' => 'https://x']]], JSON_THROW_ON_ERROR)),
        ], $history, glmApiKey: 'secret-key');

        $driver->generate(new ImageGenerationRequest(prompt: 'a red cat'));

        $this->assertSame('Bearer secret-key', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testGenerateThrowsOnApiErrorEnvelope(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode(['error' => ['message' => 'Invalid API Key']], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GLM API error: Invalid API Key');

        $driver->generate(new ImageGenerationRequest(prompt: 'hi'));
    }

    public function testGenerateRejectsAnUnknownModelWhenNoPricingIsRegistered(): void
    {
        $driver = $this->driverWithMockedResponses([], extraModelPricing: []);

        $this->expectException(UnknownModelException::class);

        $driver->generate(new ImageGenerationRequest(prompt: 'hi', model: 'cogview-4'));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('glm-image', $driver->getId());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableReflectsApiKeyPresence(): void
    {
        $withKey = $this->driverWithMockedResponses([], glmApiKey: 'test-key');
        $withoutKey = $this->driverWithMockedResponses([], glmApiKey: '');

        $this->assertTrue($withKey->isAvailable());
        $this->assertFalse($withoutKey->isAvailable());
    }

    public function testEstimateCostMultipliesPerImagePriceByCount(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $estimate = $driver->estimateCost(new ImageGenerationRequest(prompt: 'hi', model: 'cogview-4', count: 3));

        $this->assertEqualsWithDelta(0.12, $estimate->estimatedCostUsd, 1e-9);
    }
}
