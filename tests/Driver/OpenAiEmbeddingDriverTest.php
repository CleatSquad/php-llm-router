<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\OpenAiEmbeddingDriver;
use LlmRouter\DTO\EmbeddingRequest;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class OpenAiEmbeddingDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses): OpenAiEmbeddingDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handlerStack]);

        return new OpenAiEmbeddingDriver(new HttpClient($client), openAiApiKey: 'test-key');
    }

    public function testGetTypeIsEmbedding(): void
    {
        $this->assertSame(DriverType::EMBEDDING, $this->driverWithMockedResponses([])->getType());
    }

    public function testEmbedSortsByIndexAndExtractsUsage(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'model' => 'text-embedding-3-small',
                // Deliberately out of order to verify index-based sorting.
                'data' => [
                    ['index' => 1, 'embedding' => [0.4, 0.5]],
                    ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'usage' => ['prompt_tokens' => 6, 'total_tokens' => 6],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->embed(new EmbeddingRequest(['hello', 'world']));

        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5]], $response->embeddings);
        $this->assertSame(6, $response->promptTokens);
        $this->assertSame(6, $response->totalTokens);
        $this->assertGreaterThan(0.0, $response->costUsd);
    }

    public function testEmbedForTextConvenienceFactory(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'model' => 'text-embedding-3-small',
                'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
                'usage' => ['prompt_tokens' => 2, 'total_tokens' => 2],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->embed(EmbeddingRequest::forText('hello'));

        $this->assertSame([0.1, 0.2], $response->first());
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

    public function testIsAvailableReflectsApiKey(): void
    {
        $this->assertTrue($this->driverWithMockedResponses([])->isAvailable());
        $this->assertFalse((new OpenAiEmbeddingDriver(new HttpClient(new Client())))->isAvailable());
    }
}
