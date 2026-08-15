<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\ClaudeDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Enum\ReasoningEffort;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Invariant protected: reasoning is requested in the shape current Claude
 *   models accept, comes back on the DTO rather than mixed into the answer,
 *   and survives to the next turn.
 * Bug covered: supportsReasoning() returned true while nothing was sent and
 *   thinking blocks in the response were dropped on the floor — you paid for
 *   thinking tokens and saw none of it.
 * Type: feature + characterisation of the wire format.
 */
final class ClaudeDriverReasoningTest extends TestCase
{
    /** @var array<int, Psr7Request> */
    private array $sent = [];

    /**
     * @param array<int, Response> $responses
     */
    private function driver(array $responses): ClaudeDriver
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return new ClaudeDriver(new HttpClient(new Client(['handler' => $stack])));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPayload(): array
    {
        $body = (string) $this->sent[array_key_last($this->sent)]['request']->getBody();

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function answer(): Response
    {
        return new Response(200, [], json_encode([
            'model' => 'claude-sonnet-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Let me work through this.', 'signature' => 'sig-abc'],
                ['type' => 'text', 'text' => 'The answer is 42.'],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 100,
                'output_tokens_details' => ['thinking_tokens' => 70],
            ],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR));
    }

    private function request(?ReasoningEffort $effort, bool $include = false): LLMRequest
    {
        return new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            reasoningEffort: $effort,
            includeReasoning: $include,
        );
    }

    public function testAdaptiveThinkingIsSentNotTheDeprecatedBudgetForm(): void
    {
        $this->driver([$this->answer()])->chat($this->request(ReasoningEffort::High, include: true));

        $payload = $this->lastPayload();

        // budget_tokens is deprecated on Claude 4.6 and a 400 on 4.7+, so
        // sending it would break the library on every current model.
        $this->assertSame('adaptive', $payload['thinking']['type']);
        $this->assertArrayNotHasKey('budget_tokens', $payload['thinking']);
        $this->assertSame('high', $payload['output_config']['effort']);
    }

    public function testAskingForTheTraceOptsIntoSummarisedDisplay(): void
    {
        $this->driver([$this->answer()])->chat($this->request(ReasoningEffort::Medium, include: true));

        // On the newest models display defaults to "omitted": the block still
        // arrives and is still billed, but its thinking field is empty.
        $this->assertSame('summarized', $this->lastPayload()['thinking']['display']);
    }

    public function testNotAskingForTheTraceLeavesDisplayOmitted(): void
    {
        $this->driver([$this->answer()])->chat($this->request(ReasoningEffort::Medium));

        $this->assertSame('omitted', $this->lastPayload()['thinking']['display']);
    }

    public function testNoEffortAtAllSendsNoThinkingConfigSoTheModelDefaultStands(): void
    {
        $this->driver([$this->answer()])->chat($this->request(null));

        $payload = $this->lastPayload();

        $this->assertArrayNotHasKey('thinking', $payload);
        $this->assertArrayNotHasKey('output_config', $payload);
    }

    public function testEffortNoneDisablesThinkingExplicitly(): void
    {
        $this->driver([$this->answer()])->chat($this->request(ReasoningEffort::None));

        $this->assertSame(['type' => 'disabled'], $this->lastPayload()['thinking']);
    }

    public function testTheTraceComesBackSeparatelyFromTheAnswer(): void
    {
        $response = $this->driver([$this->answer()])->chat($this->request(ReasoningEffort::High, include: true));

        $this->assertSame('The answer is 42.', $response->content, 'reasoning must not leak into the answer');
        $this->assertSame('Let me work through this.', $response->reasoning);
        $this->assertTrue($response->hasReasoning());
        $this->assertSame(70, $response->reasoningTokens);
        $this->assertSame('sig-abc', $response->reasoningSignature);
    }

    public function testTheTraceIsReplayedOnTheNextTurnWithItsSignature(): void
    {
        $driver = $this->driver([$this->answer(), $this->answer()]);

        $first = $driver->chat($this->request(ReasoningEffort::High, include: true));

        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi'], $first->toMessage()],
            reasoningEffort: ReasoningEffort::High,
            includeReasoning: true,
        ));

        // Anthropic requires a thinking-enabled assistant turn to start with
        // its thinking block, signature included, or it rejects the request.
        $assistant = $this->lastPayload()['messages'][1];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertSame('thinking', $assistant['content'][0]['type']);
        $this->assertSame('Let me work through this.', $assistant['content'][0]['thinking']);
        $this->assertSame('sig-abc', $assistant['content'][0]['signature']);
        $this->assertSame('text', $assistant['content'][1]['type']);
    }

    public function testAnAssistantMessageWithoutATraceIsUnchanged(): void
    {
        $driver = $this->driver([$this->answer()]);

        $driver->chat(new LLMRequest(messages: [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'plain answer'],
        ]));

        $this->assertSame('plain answer', $this->lastPayload()['messages'][1]['content']);
    }

    public function testStreamedThinkingReachesTheCallbackAndNeverTheYieldedText(): void
    {
        $sse = implode('', array_map(
            static fn (array $event): string => 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n",
            [
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'First I ']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'check the units.']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'It is ']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => '42.']],
                ['type' => 'message_stop'],
            ]
        ));

        $thinking = '';
        $request = new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            reasoningEffort: ReasoningEffort::High,
            includeReasoning: true,
            onReasoning: function (string $fragment) use (&$thinking): void {
                $thinking .= $fragment;
            },
        );

        $chunks = iterator_to_array($this->driver([new Response(200, [], $sse)])->stream($request));

        // The generator's values are the visible answer. An application that
        // echoes them must not end up printing the model's scratch work.
        $this->assertSame(['It is ', '42.'], $chunks);
        $this->assertSame('First I check the units.', $thinking);
    }

    public function testStreamingWithoutACallbackSimplyDropsTheTrace(): void
    {
        $sse = 'data: ' . json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'ignored'],
        ], JSON_THROW_ON_ERROR) . "\n\n"
            . 'data: ' . json_encode([
                'type' => 'content_block_delta',
                'index' => 1,
                'delta' => ['type' => 'text_delta', 'text' => 'hello'],
            ], JSON_THROW_ON_ERROR) . "\n\n"
            . 'data: ' . json_encode(['type' => 'message_stop'], JSON_THROW_ON_ERROR) . "\n\n";

        $chunks = iterator_to_array(
            $this->driver([new Response(200, [], $sse)])->stream($this->request(ReasoningEffort::High))
        );

        $this->assertSame(['hello'], $chunks);
    }
}
