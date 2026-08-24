<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\ClaudeDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ClaudeDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithMockedResponses(array $responses, array &$history = []): ClaudeDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new ClaudeDriver(new HttpClient($client), anthropicApiKey: 'test-key');
    }

    private function textResponse(string $text): Response
    {
        return new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
            'model' => 'claude-sonnet-5',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR));
    }

    public function testChatMapsSystemPromptAndExtractsUsage(): void
    {
        $driver = $this->driverWithMockedResponses([$this->textResponse('Bonjour !')]);

        $response = $driver->chat(new LLMRequest(messages: [
            ['role' => 'system', 'content' => 'Tu es un assistant.'],
            ['role' => 'user', 'content' => 'Salut'],
        ], model: 'claude-sonnet-5'));

        $this->assertSame('Bonjour !', $response->content);
        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertGreaterThan(0.0, $response->costUsd);
    }

    /**
     * Real bug fixed 2026-08-01: splitSystemMessages() used to pass
     * $message['content'] straight through, so an OpenAI-shaped
     * multi-part vision message reached Anthropic's API in a shape it
     * doesn't understand at all. Asserts the actual outgoing request body
     * uses Anthropic's native {type: image, source: {type: base64,
     * media_type, data}} block instead.
     */
    public function testChatTranslatesVisionContentToAnthropicNativeImageBlocks(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->textResponse('Je vois une photo.')], $history);

        $driver->chat(new LLMRequest(messages: [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Que vois-tu ?'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,ZmFrZWRhdGE=']],
            ]],
        ], model: 'claude-sonnet-5'));

        $sentBody = json_decode((string) $history[0]['request']->getBody(), true);
        $content = $sentBody['messages'][0]['content'];

        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('Que vois-tu ?', $content[0]['text']);
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('base64', $content[1]['source']['type']);
        $this->assertSame('image/jpeg', $content[1]['source']['media_type']);
        $this->assertSame('ZmFrZWRhdGE=', $content[1]['source']['data']);
    }

    public function testChatKeepsPlainTextContentUnchanged(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([$this->textResponse('ok')], $history);

        $driver->chat(new LLMRequest(messages: [
            ['role' => 'user', 'content' => 'Un message texte normal'],
        ], model: 'claude-sonnet-5'));

        $sentBody = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('Un message texte normal', $sentBody['messages'][0]['content']);
    }

    public function testStreamCapturesUsageFromMessageStartAndMessageDeltaEvents(): void
    {
        $sse = implode('', array_map(
            static fn (array $event): string => 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n",
            [
                ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 1]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Bonjour']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
                ['type' => 'message_stop'],
            ]
        ));

        $driver = $this->driverWithMockedResponses([new Response(200, [], $sse)]);

        $gen = $driver->stream(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'claude-sonnet-5'
        ));
        $chunks = iterator_to_array($gen);
        $return = $gen->getReturn();

        $this->assertSame(['Bonjour'], $chunks);
        $this->assertIsArray($return);
        $this->assertNull($return['tool_calls']);
        $this->assertSame(10, $return['prompt_tokens']);
        $this->assertSame(5, $return['completion_tokens']);
        $this->assertSame(15, $return['total_tokens']);
        // (10 * 0.003 + 5 * 0.015) / 1000
        $this->assertEqualsWithDelta(0.000105, $return['cost_usd'], 1e-9);
    }
}
