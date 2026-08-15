<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\GeminiEmbeddingDriver;
use CleatSquad\LlmRouter\DTO\EmbeddingRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GeminiEmbeddingDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses): GeminiEmbeddingDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handlerStack]);

        return new GeminiEmbeddingDriver(new HttpClient($client), geminiApiKey: 'test-key');
    }

    public function testGetTypeIsEmbedding(): void
    {
        $this->assertSame(DriverType::EMBEDDING, $this->driverWithMockedResponses([])->getType());
    }

    public function testEmbedReturnsOneVectorPerInputInOrder(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'embeddings' => [
                    ['values' => [0.1, 0.2, 0.3]],
                    ['values' => [0.4, 0.5, 0.6]],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->embed(new EmbeddingRequest(['hello', 'world']));

        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $response->embeddings);
        $this->assertSame(0.0, $response->costUsd);
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
