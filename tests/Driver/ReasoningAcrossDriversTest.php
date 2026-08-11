<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\DeepSeekDriver;
use LlmRouter\Driver\GeminiDriver;
use LlmRouter\Driver\GroqDriver;
use LlmRouter\Driver\KimiDriver;
use LlmRouter\Driver\MistralDriver;
use LlmRouter\Driver\OllamaDriver;
use LlmRouter\Driver\OpenAiDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Enum\ReasoningEffort;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Invariant protected: every driver translates the neutral reasoning effort
 *   into its own provider's dialect, and returns the trace on the DTO rather
 *   than folded into the answer.
 * Bug covered: supportsReasoning() reported a capability no driver acted on —
 *   Claude's thinking blocks were dropped and DeepSeek's reasoning_content was
 *   never read, so callers paid for reasoning tokens and saw nothing.
 * Type: feature + wire-format characterisation.
 */
final class ReasoningAcrossDriversTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    /**
     * A PSR-7 body is a stream: reusing one Response instance across calls
     * hands the second caller an already-consumed, empty body. Each queued
     * response therefore has to be built fresh from its payload.
     */
    private function http(Response $response, ?Response $first = null): HttpClient
    {
        $clone = static function (Response $r): callable {
            $body = (string) $r->getBody();

            return static fn (): Response => new Response($r->getStatusCode(), [], $body);
        };

        $make = $clone($response);
        $makeFirst = $first !== null ? $clone($first) : null;

        $queue = [];
        for ($i = 0; $i < 3; $i++) {
            if ($makeFirst !== null) {
                $queue[] = $makeFirst();
            }
            $queue[] = $make();
        }

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->sent));

        return new HttpClient(new Client(['handler' => $stack]));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPayload(): array
    {
        return json_decode(
            (string) $this->sent[array_key_last($this->sent)]['request']->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function request(ReasoningEffort $effort = ReasoningEffort::High, bool $include = true): LLMRequest
    {
        return new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            reasoningEffort: $effort,
            includeReasoning: $include,
        );
    }

    /**
     * A chat-completions style answer carrying its trace in $field.
     */
    private function chatCompletion(string $field): Response
    {
        return new Response(200, [], json_encode([
            'model' => 'm',
            'choices' => [[
                'message' => ['content' => 'The answer.', $field => 'The reasoning.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{0: callable(HttpClient): LLMDriverInterface, 1: Response, 2: string}>
     */
    public static function tracingDrivers(): array
    {
        return [
            'DeepSeek' => [
                static fn (HttpClient $h): LLMDriverInterface => new DeepSeekDriver($h),
                'reasoning_content',
            ],
            'Kimi' => [
                static fn (HttpClient $h): LLMDriverInterface => new KimiDriver($h),
                'reasoning_content',
            ],
            'Mistral' => [
                static fn (HttpClient $h): LLMDriverInterface => new MistralDriver($h),
                'reasoning_content',
            ],
            'Groq' => [
                static fn (HttpClient $h): LLMDriverInterface => new GroqDriver($h),
                'reasoning',
            ],
        ];
    }

    /**
     * @param callable(HttpClient): LLMDriverInterface $make
     */
    #[DataProvider('tracingDrivers')]
    public function testTheTraceComesBackSeparatelyFromTheAnswer(callable $make, string $field): void
    {
        $response = $make($this->http($this->chatCompletion($field)))->chat($this->request());

        $this->assertSame('The answer.', $response->content, 'reasoning must not leak into the answer');
        $this->assertSame('The reasoning.', $response->reasoning);
        $this->assertTrue($response->hasReasoning());
    }

    /**
     * @param callable(HttpClient): LLMDriverInterface $make
     */
    #[DataProvider('tracingDrivers')]
    public function testTheTraceIsReplayedUnderTheProviderOwnKey(callable $make, string $field): void
    {
        $driver = $make($this->http($this->chatCompletion($field)));

        $first = $driver->chat($this->request());
        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi'], $first->toMessage()],
            reasoningEffort: ReasoningEffort::High,
            includeReasoning: true,
        ));

        $assistant = $this->lastPayload()['messages'][1];

        // Moonshot documents that dropping the trace mid tool-calling loop
        // degrades the model; Mistral says the same. The neutral key must be
        // translated, not forwarded verbatim.
        $this->assertSame('The reasoning.', $assistant[$field]);
        $this->assertArrayNotHasKey('reasoning_signature', $assistant);
        if ($field !== 'reasoning') {
            $this->assertArrayNotHasKey('reasoning', $assistant);
        }
    }

    public function testOpenAiSendsTheEffortAndStripsTheNeutralKeysItWouldReject(): void
    {
        // gpt-4o rejects reasoning_effort outright, so a reasoning request has
        // to name a reasoning model — registered here the way a caller would.
        $driver = new OpenAiDriver($this->http($this->chatCompletion('unused')), extraModelPricing: [
            'gpt-5' => ['input' => 0.00125, 'output' => 0.01],
        ]);

        $driver->chat(new LLMRequest(
            model: 'gpt-5',
            messages: [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => 'prior', 'reasoning' => 'private thoughts'],
            ],
            reasoningEffort: ReasoningEffort::High,
        ));

        $payload = $this->lastPayload();

        $this->assertSame('high', $payload['reasoning_effort']);
        // OpenAI never returns a trace, so there is nothing to replay — and an
        // unknown message field would be rejected.
        $this->assertArrayNotHasKey('reasoning', $payload['messages'][1]);
    }

    public function testOpenAiNeverReportsATraceEvenWhenAsked(): void
    {
        $driver = new OpenAiDriver($this->http($this->chatCompletion('reasoning_content')), extraModelPricing: [
            'gpt-5' => ['input' => 0.00125, 'output' => 0.01],
        ]);

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gpt-5',
            reasoningEffort: ReasoningEffort::High,
            includeReasoning: true,
        ));

        // The provider keeps its reasoning private and only bills for it.
        $this->assertNull($response->reasoning);
        $this->assertFalse($response->hasReasoning());
    }

    public function testDeepSeekEnablesThinkingExplicitly(): void
    {
        (new DeepSeekDriver($this->http($this->chatCompletion('reasoning_content'))))->chat($this->request());

        $payload = $this->lastPayload();

        $this->assertSame(['type' => 'enabled'], $payload['thinking']);
        $this->assertSame('high', $payload['reasoning_effort']);
    }

    public function testGroqAsksForTheTraceToBeParsedIntoItsOwnField(): void
    {
        (new GroqDriver($this->http($this->chatCompletion('reasoning'))))->chat($this->request());

        $payload = $this->lastPayload();

        // Without this, Groq folds the reasoning into the answer as <think> tags.
        $this->assertSame('parsed', $payload['reasoning_format']);
        $this->assertSame('default', $payload['reasoning_effort']);
    }

    public function testGroqEffortNoneTurnsThinkingOff(): void
    {
        (new GroqDriver($this->http($this->chatCompletion('reasoning'))))
            ->chat($this->request(ReasoningEffort::None, include: false));

        $this->assertSame('none', $this->lastPayload()['reasoning_effort']);
    }

    public function testMistralSwitchesToItsReasoningPromptMode(): void
    {
        (new MistralDriver($this->http($this->chatCompletion('reasoning_content'))))->chat($this->request());

        $this->assertSame('reasoning', $this->lastPayload()['prompt_mode']);
    }

    public function testGeminiMapsEffortOntoAThinkingBudgetAndAsksForThoughts(): void
    {
        $response = new Response(200, [], json_encode([
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'Working it out.', 'thought' => true],
                    ['text' => 'The answer.'],
                ]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 2, 'totalTokenCount' => 3],
        ], JSON_THROW_ON_ERROR));

        $result = (new GeminiDriver($this->http($response)))->chat($this->request());

        $payload = $this->lastPayload();
        $this->assertTrue($payload['generationConfig']['thinkingConfig']['includeThoughts']);
        $this->assertGreaterThan(0, $payload['generationConfig']['thinkingConfig']['thinkingBudget']);

        // A Gemini thought is an ordinary text part flagged thought:true, so
        // failing to filter it would splice the reasoning into the answer.
        $this->assertSame('The answer.', $result->content);
        $this->assertSame('Working it out.', $result->reasoning);
    }

    public function testGeminiEffortNoneTurnsThinkingOff(): void
    {
        $response = new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'x']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ], JSON_THROW_ON_ERROR));

        (new GeminiDriver($this->http($response)))->chat($this->request(ReasoningEffort::None, include: false));

        $this->assertSame(0, $this->lastPayload()['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

    public function testOllamaPassesTheEffortLevelStraightThroughAndReadsTheTrace(): void
    {
        $response = new Response(200, [], json_encode([
            'model' => 'qwen3',
            'message' => ['content' => 'The answer.', 'thinking' => 'The reasoning.'],
            'done_reason' => 'stop',
            'prompt_eval_count' => 1,
            'eval_count' => 2,
        ], JSON_THROW_ON_ERROR));

        $tags = new Response(200, [], json_encode(['models' => [['name' => 'qwen3']]], JSON_THROW_ON_ERROR));
        $result = (new OllamaDriver($this->http($response, $tags), ollamaModel: 'qwen3'))
            ->chat($this->request(ReasoningEffort::Medium));

        // Ollama's `think` takes these very level names, so the neutral effort
        // needs no translation at all.
        $this->assertSame('medium', $this->lastPayload()['think']);
        $this->assertSame('The answer.', $result->content);
        $this->assertSame('The reasoning.', $result->reasoning);
    }

    public function testOllamaEffortNoneDisablesThinking(): void
    {
        $response = new Response(200, [], json_encode([
            'model' => 'qwen3',
            'message' => ['content' => 'x'],
            'done_reason' => 'stop',
        ], JSON_THROW_ON_ERROR));

        $tags = new Response(200, [], json_encode(['models' => [['name' => 'qwen3']]], JSON_THROW_ON_ERROR));
        (new OllamaDriver($this->http($response, $tags), ollamaModel: 'qwen3'))
            ->chat($this->request(ReasoningEffort::None, include: false));

        $this->assertFalse($this->lastPayload()['think']);
    }

    public function testAskingForNoEffortLeavesEveryPayloadUntouched(): void
    {
        (new DeepSeekDriver($this->http($this->chatCompletion('reasoning_content'))))
            ->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));

        $payload = $this->lastPayload();

        // Omitting the effort must change nothing about the request that was
        // sent before this feature existed.
        $this->assertArrayNotHasKey('thinking', $payload);
        $this->assertArrayNotHasKey('reasoning_effort', $payload);
    }

    public function testEveryDriverReportsItCanExpressAReasoningRequest(): void
    {
        $http = $this->http($this->chatCompletion('reasoning_content'));

        foreach ([
            new DeepSeekDriver($http),
            new KimiDriver($http),
            new MistralDriver($http),
            new GroqDriver($http),
            new OpenAiDriver($http),
            new GeminiDriver($http),
            new OllamaDriver($http),
        ] as $driver) {
            $this->assertTrue($driver->supportsReasoning(), $driver::class . ' should report reasoning support');
        }
    }
}
