<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\OllamaDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class OllamaDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string $ollamaModel = 'llama3'): OllamaDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new OllamaDriver(new HttpClient($client), ollamaModel: $ollamaModel);
    }

    private function tagsResponse(array $modelNames = ['llama3']): Response
    {
        return new Response(200, [], json_encode([
            'models' => array_map(static fn (string $name) => ['name' => $name], $modelNames),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * chat()/stream() resolve the model by calling resolveModel(), which
     * itself calls getModels() (a GET /api/tags) before the actual POST
     * /api/chat — so every successful chat() exercise needs two queued
     * responses, in that order.
     */
    private function chatResponse(): Response
    {
        return new Response(200, [], json_encode([
            'message' => ['content' => 'Bonjour !'],
            'done_reason' => 'stop',
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Real incident: a requested model absent locally (e.g. gpt-4o-mini,
     * meant for a different driver entirely) used to fall back to whatever
     * local model came first that didn't contain "embed"/"nomic" — the
     * actually-installed embedding model "zylonai/multilingual-e5-large"
     * slipped through that filter and got picked as a "chat" substitute,
     * guaranteed to fail ("does not support chat").
     */
    public function testResolveModelFallbackNeverPicksAnEmbeddingModelListedFirst(): void
    {
        $driver = $this->driverWithMockedResponses([
            $this->tagsResponse(['zylonai/multilingual-e5-large', 'llama3.2:3b']),
            $this->chatResponse(),
        ], ollamaModel: 'llama3.2:3b');

        $response = $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'Salut']], model: 'gpt-4o-mini'));

        $this->assertSame('llama3.2:3b', $response->model);
    }

    public function testChatMapsResponseAndIsAlwaysFree(): void
    {
        $driver = $this->driverWithMockedResponses([$this->tagsResponse(['llama3']), $this->chatResponse()]);

        $response = $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'Salut']]));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame('stop', $response->finishReason);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        $this->assertSame(0.0, $response->costUsd);
        $this->assertSame('llama3', $response->model);
    }

    public function testChatUsesOllamaReportedDurationAsLatencyWhenPresent(): void
    {
        $driver = $this->driverWithMockedResponses([
            $this->tagsResponse(['llama3']),
            new Response(200, [], json_encode([
                'message' => ['content' => 'ok'],
                'done_reason' => 'stop',
                'prompt_eval_count' => 1,
                'eval_count' => 1,
                'total_duration' => 250_000_000, // nanoseconds
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));

        $this->assertSame(250, $response->latencyMs);
    }

    public function testChatWrapsTransportFailureAsARequestFailure(): void
    {
        $driver = $this->driverWithMockedResponses([$this->tagsResponse(['llama3']), new Response(500, [], 'boom')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ollama request failed');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatThrowsOnInvalidJsonPayload(): void
    {
        $driver = $this->driverWithMockedResponses([$this->tagsResponse(['llama3']), new Response(200, [], 'not json')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ollama returned invalid JSON payload');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('ollama', $driver->getId());
        $this->assertSame('Ollama Local', $driver->getName());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableTrueWhenTagsEndpointRespondsOk(): void
    {
        $driver = $this->driverWithMockedResponses([$this->tagsResponse()]);

        $this->assertTrue($driver->isAvailable());
    }

    public function testIsAvailableFalseWhenTagsEndpointFails(): void
    {
        $driver = $this->driverWithMockedResponses([new Response(500, [], 'boom')]);

        $this->assertFalse($driver->isAvailable());
    }

    public function testCapabilityFlags(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertTrue($driver->supportsStreaming());
        $this->assertFalse($driver->supportsTools());
        $this->assertFalse($driver->supportsVision());
        // Was false until this driver learned to express a reasoning request.
        // It reports what the *driver* can translate, not what the model you
        // picked will accept — see "Reasoning" in the README for which models
        // actually honour it.
        $this->assertTrue($driver->supportsReasoning());
    }

    public function testEstimateCostIsAlwaysFree(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $estimate = $driver->estimateCost(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));

        $this->assertSame(0.0, $estimate->inputCostPer1k);
        $this->assertSame(0.0, $estimate->outputCostPer1k);
        $this->assertSame(0, $estimate->estimatedTokens);
        $this->assertSame(0.0, $estimate->estimatedCostUsd);
    }

    public function testGetModelsParsesLocalTagsList(): void
    {
        $driver = $this->driverWithMockedResponses([$this->tagsResponse(['llama3', 'mistral'])]);

        $this->assertSame(['llama3', 'mistral'], $driver->getModels());
    }

    public function testGetModelsReturnsEmptyArrayOnFailure(): void
    {
        $driver = $this->driverWithMockedResponses([new Response(500, [], 'boom')]);

        $this->assertSame([], $driver->getModels());
    }
}
