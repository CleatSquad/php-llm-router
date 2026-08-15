<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\MistralEmbeddingDriver;
use CleatSquad\LlmRouter\DTO\EmbeddingRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MistralEmbeddingDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses): MistralEmbeddingDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handlerStack]);

        return new MistralEmbeddingDriver(new HttpClient($client), mistralApiKey: 'test-key');
    }

    public function testGetTypeIsEmbedding(): void
    {
        $this->assertSame(DriverType::EMBEDDING, $this->driverWithMockedResponses([])->getType());
    }

    public function testEmbedExtractsUsageAndCost(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'model' => 'mistral-embed',
                'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
                'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->embed(EmbeddingRequest::forText('hello'));

        $this->assertSame([0.1, 0.2], $response->first());
        $this->assertSame(3, $response->promptTokens);
        $this->assertGreaterThan(0.0, $response->costUsd);
    }

    public function testEmbedThrowsOnApiError(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'error' => ['message' => 'invalid api key'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid api key');
        $driver->embed(EmbeddingRequest::forText('hello'));
    }
}
