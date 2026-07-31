<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\OllamaEmbeddingDriver;
use LlmRouter\DTO\EmbeddingRequest;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class OllamaEmbeddingDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses): OllamaEmbeddingDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handlerStack]);

        return new OllamaEmbeddingDriver(new HttpClient($client));
    }

    public function testGetTypeIsEmbedding(): void
    {
        $this->assertSame(DriverType::EMBEDDING, $this->driverWithMockedResponses([])->getType());
    }

    public function testEmbedIsFreeAndReturnsEmbeddings(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'model' => 'nomic-embed-text',
                'embeddings' => [[0.1, 0.2, 0.3]],
                'prompt_eval_count' => 4,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->embed(EmbeddingRequest::forText('hello'));

        $this->assertSame([0.1, 0.2, 0.3], $response->first());
        $this->assertSame(0.0, $response->costUsd);
        $this->assertSame(4, $response->promptTokens);
    }
}
