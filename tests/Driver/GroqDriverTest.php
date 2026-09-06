<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\GroqDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GroqDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     * @param string|array<string> $groqApiKey
     */
    private function driverWithMockedResponses(array $responses, array &$history = [], string|array $groqApiKey = 'test-key'): GroqDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new GroqDriver(new HttpClient($client), groqApiKey: $groqApiKey);
    }

    private function chatResponse(string $content = 'Bonjour !', string $model = 'openai/gpt-oss-20b'): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'model' => $model,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatRotatesApiKeyPoolRoundRobin(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses(
            [$this->chatResponse(), $this->chatResponse(), $this->chatResponse(), $this->chatResponse()],
            $history,
            groqApiKey: ['k1', 'k2', 'k3']
        );

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => '1']]));
        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => '2']]));
        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => '3']]));
        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => '4']]));

        $this->assertSame('Bearer k1', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer k2', $history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer k3', $history[2]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer k1', $history[3]['request']->getHeaderLine('Authorization'));
    }

    public function testChatMapsResponseAndComputesCostFromPricingTable(): void
    {
        $driver = $this->driverWithMockedResponses([$this->chatResponse('Bonjour !', 'openai/gpt-oss-120b')]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'Salut']],
            model: 'openai/gpt-oss-120b'
        ));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        $this->assertSame('stop', $response->finishReason);
        // (10 * 0.00015 + 5 * 0.0006) / 1000
        $this->assertEqualsWithDelta(0.0000045, $response->costUsd, 1e-9);
    }

    public function testChatSendsBearerAuthorizationHeader(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->chatResponse()], $history, groqApiKey: 'secret-key');

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
        $this->expectExceptionMessage('Groq API error: Invalid API Key');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testChatWrapsTransportFailureAsARequestFailure(): void
    {
        $driver = $this->driverWithMockedResponses([new Response(500, [], 'boom')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Groq request failed');

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function testIdentity(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame('groq', $driver->getId());
        $this->assertSame('Groq Direct', $driver->getName());
        $this->assertSame(DriverType::LLM, $driver->getType());
    }

    public function testIsAvailableReflectsApiKeyPresence(): void
    {
        $withKey = $this->driverWithMockedResponses([], groqApiKey: 'test-key');
        $withoutKey = $this->driverWithMockedResponses([], groqApiKey: '');
        $withEmptyArray = $this->driverWithMockedResponses([], groqApiKey: []);
        $withEmptyStrings = $this->driverWithMockedResponses([], groqApiKey: ['', '  ']);

        $this->assertTrue($withKey->isAvailable());
        $this->assertFalse($withoutKey->isAvailable());
        $this->assertFalse($withEmptyArray->isAvailable());
        $this->assertFalse($withEmptyStrings->isAvailable());
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

    public function testGptOss120bDeclaresItsContextWindow(): void
    {
        // Real 400 "Request too large" observed in production (2026-09-02):
        // ContextWindowConstraint had nothing to enforce because no
        // driver declared a 'context' entry, so it silently let any prompt
        // size through. console.groq.com/docs/model/openai/gpt-oss-120b.
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame(131_072, $driver->contextWindowFor('openai/gpt-oss-120b'));
    }

    public function testEstimateCostUsesPricingForResolvedModelAndDefaultOutputBudget(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        // 16 chars => ceil(16/4) = 4 input tokens.
        $request = new LLMRequest(
            messages: [['role' => 'user', 'content' => 'a message of 16c']],
            model: 'openai/gpt-oss-120b'
        );

        $estimate = $driver->estimateCost($request);

        $this->assertSame(0.00015, $estimate->inputCostPer1k);
        $this->assertSame(0.0006, $estimate->outputCostPer1k);
        $this->assertSame(4 + 200, $estimate->estimatedTokens);
        $expectedCost = ((4 * 0.00015) + (200 * 0.0006)) / 1000;
        $this->assertEqualsWithDelta($expectedCost, $estimate->estimatedCostUsd, 1e-9);
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
            model: 'openai/gpt-oss-20b'
        ));

        $chunks = iterator_to_array($gen);
        $return = $gen->getReturn();

        $this->assertSame(['Bonjour'], $chunks);
        $this->assertIsArray($return);
        $this->assertNull($return['tool_calls']);
        $this->assertSame(10, $return['prompt_tokens']);
        $this->assertSame(5, $return['completion_tokens']);
        $this->assertSame(15, $return['total_tokens']);
        // (10 * 0.000075 + 5 * 0.0003) / 1000
        $this->assertEqualsWithDelta(0.00000225, $return['cost_usd'], 1e-9);

        $requestPayload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertTrue($requestPayload['stream_options']['include_usage'] ?? false);
    }

    /**
     * Regression: ConcreteLlmProvider::stream() used to build its
     * LibLLMRequest without `tools`, so this driver never even saw a
     * schema on the streaming path the real chat actually uses — the
     * request payload's `tools` key was simply absent, not empty.
     */
    public function testStreamSerializesToolSchemasIntoTheHttpPayload(): void
    {
        $sse = 'data: '
            . json_encode(['choices' => [['delta' => ['tool_calls' => [[
                'index' => 0,
                'id' => 'call_1',
                'function' => ['name' => 'write_codebase_file', 'arguments' => '{"path":"ghost-success.md"'],
            ]]]]]])
            . "\n\n"
            . 'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [[
                'index' => 0,
                'function' => ['arguments' => ',"content":"TEST GHOST SUCCESS"}'],
            ]]]]]]) . "\n\n"
            . 'data: ' . json_encode([
                'choices' => [['delta' => []]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            ]) . "\n\n"
            . "data: [DONE]\n\n";

        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sse),
        ], $history);

        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'write_codebase_file',
                'description' => 'Creates or overwrites a file safely within the workspace boundaries.',
                'parameters' => ['type' => 'object', 'required' => ['path', 'content']],
            ],
        ]];

        $gen = $driver->stream(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'crée ghost-success.md']],
            model: 'openai/gpt-oss-20b',
            tools: $tools,
        ));

        iterator_to_array($gen);
        $result = $gen->getReturn();

        $requestPayload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame($tools, $requestPayload['tools'] ?? null, 'the HTTP payload must actually carry the tool schema');

        $this->assertSame('write_codebase_file', $result['tool_calls'][0]['function']['name']);
        $this->assertSame(
            '{"path":"ghost-success.md","content":"TEST GHOST SUCCESS"}',
            $result['tool_calls'][0]['function']['arguments']
        );
    }

    public function testGetModelsReturnsPricingTableKeys(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $this->assertSame(
            [
                'qwen/qwen3.6-27b',
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
            ],
            $driver->getModels()
        );
    }
}
