<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\ClaudeDriver;
use CleatSquad\LlmRouter\Driver\OpenAiDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Enum\ReasoningEffort;
use CleatSquad\LlmRouter\Exception\UnsupportedReasoningException;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Invariant protected: a reasoning request either reaches a model that can
 *   serve it, or fails with a message naming what went wrong — never a bare
 *   provider 400 — and a model whose thinking cannot be switched off is never
 *   sent an instruction to switch it off.
 * Bug covered: the 2.1.0 reasoning work sent `reasoning_effort` to whatever
 *   model was resolved. On OpenAI's shipped catalogue that is gpt-4o, which
 *   rejects the parameter outright: every reasoning request through
 *   OpenAiDriver was a guaranteed 400. Claude Fable 5 has the mirror problem —
 *   `thinking: {type: "disabled"}` is a 400 there, so asking for no reasoning
 *   broke the call.
 * Type: regression + wire-format characterisation.
 */
final class ModelCapabilityTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    private function http(Response $response): HttpClient
    {
        $body = (string) $response->getBody();
        $stack = HandlerStack::create(new MockHandler(array_map(
            static fn (): Response => new Response(200, [], $body),
            range(1, 3)
        )));
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

    private function claudeAnswer(): Response
    {
        return new Response(200, [], json_encode([
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR));
    }

    private function openAiAnswer(): Response
    {
        return new Response(200, [], json_encode([
            'model' => 'gpt-4o',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], JSON_THROW_ON_ERROR));
    }

    public function testTheCurrentAnthropicModelsAreInTheCatalogue(): void
    {
        $models = (new ClaudeDriver($this->http($this->claudeAnswer())))->getModels();

        // Before this, asking for claude-opus-5 — Anthropic's recommended
        // model — raised UnknownModelException, because the shipped table
        // stopped at claude-opus-4-8.
        foreach (['claude-opus-5', 'claude-fable-5', 'claude-sonnet-5', 'claude-haiku-4-5'] as $model) {
            $this->assertContains($model, $models);
        }
    }

    public function testAnthropicPricingMatchesThePublishedPerMillionRates(): void
    {
        $driver = new ClaudeDriver($this->http($this->claudeAnswer()));

        // $5 / $25 per million tokens = 0.005 / 0.025 per thousand. One prompt
        // token and a 1000-token output budget makes the output rate readable
        // straight off the estimate.
        $cost = $driver->estimateCost(new LLMRequest(
            messages: [['role' => 'user', 'content' => '']],
            model: 'claude-opus-5',
            maxTokens: 1000,
        ));

        $this->assertSame(0.005, $cost->inputCostPer1k);
        $this->assertSame(0.025, $cost->outputCostPer1k);
    }

    public function testFableIsPricedAboveOpus(): void
    {
        $driver = new ClaudeDriver($this->http($this->claudeAnswer()));
        $request = static fn (string $model): LLMRequest => new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: $model,
        );

        $this->assertGreaterThan(
            $driver->estimateCost($request('claude-opus-5'))->estimatedCostUsd,
            $driver->estimateCost($request('claude-fable-5'))->estimatedCostUsd
        );
    }

    public function testFableIsNeverToldToStopThinking(): void
    {
        $driver = new ClaudeDriver($this->http($this->claudeAnswer()));

        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'claude-fable-5',
            reasoningEffort: ReasoningEffort::None,
        ));

        // Thinking is always on for this model; `thinking: {type: "disabled"}`
        // is a 400. Saying nothing is the only way to express "don't think".
        $this->assertArrayNotHasKey('thinking', $this->lastPayload());
    }

    public function testAModelThatCanBeQuietedStillReceivesTheDisableInstruction(): void
    {
        $driver = new ClaudeDriver($this->http($this->claudeAnswer()));

        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'claude-sonnet-5',
            reasoningEffort: ReasoningEffort::None,
        ));

        $this->assertSame(['type' => 'disabled'], $this->lastPayload()['thinking']);
    }

    public function testAskingGpt4oToReasonFailsWithAnExplanationNotAProviderError(): void
    {
        $driver = new OpenAiDriver($this->http($this->openAiAnswer()));

        try {
            $driver->chat(new LLMRequest(
                messages: [['role' => 'user', 'content' => 'hi']],
                model: 'gpt-4o',
                reasoningEffort: ReasoningEffort::High,
            ));
            $this->fail('expected the unsupported reasoning request to be refused');
        } catch (UnsupportedReasoningException $e) {
            $this->assertSame('gpt-4o', $e->model);
            // The provider would have answered "400 Bad Request"; this says
            // which model was asked and what to do instead.
            $this->assertStringContainsString('gpt-4o', $e->getMessage());
            // The message names models that would have worked, so the caller
            // can act on it without opening the docs.
            $this->assertStringContainsString('gpt-5', $e->getMessage());
            $this->assertContains('o3', $e->reasoningModels);
        }
    }

    public function testTheRefusalIsARuntimeExceptionSoFailoverCanStepIn(): void
    {
        $this->assertTrue(is_subclass_of(UnsupportedReasoningException::class, RuntimeException::class));
    }

    public function testARegisteredReasoningModelIsAccepted(): void
    {
        $driver = new OpenAiDriver($this->http($this->openAiAnswer()));

        // gpt-5 ships in the catalogue as a reasoning model.
        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gpt-5',
            reasoningEffort: ReasoningEffort::High,
        ));

        // Registered entries are trusted to reason unless they say otherwise,
        // so a model this release predates is never blocked by the guard.
        $this->assertSame('high', $this->lastPayload()['reasoning_effort']);
    }

    public function testAModelCanBeRegisteredAsExplicitlyNonReasoning(): void
    {
        $driver = new OpenAiDriver($this->http($this->openAiAnswer()), extraModelPricing: [
            'some-cheap-model' => ['input' => 0.0001, 'output' => 0.0002, 'reasoning' => false],
        ]);

        $this->expectException(UnsupportedReasoningException::class);
        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'some-cheap-model',
            reasoningEffort: ReasoningEffort::High,
        ));
    }

    public function testGroqSpeaksEachModelsOwnReasoningDialect(): void
    {
        $answer = new Response(200, [], json_encode([
            'model' => 'm',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], JSON_THROW_ON_ERROR));

        $request = static fn (string $model): LLMRequest => new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: $model,
            reasoningEffort: ReasoningEffort::High,
            includeReasoning: true,
        );

        // Qwen: binary effort, and it accepts reasoning_format.
        (new \CleatSquad\LlmRouter\Driver\GroqDriver($this->http($answer)))->chat($request('qwen/qwen3.6-27b'));
        $qwen = $this->lastPayload();
        $this->assertSame('default', $qwen['reasoning_effort']);
        $this->assertSame('parsed', $qwen['reasoning_format']);

        // GPT-OSS: graded effort, and reasoning_format is rejected outright —
        // sending Qwen's spelling here was a guaranteed 400.
        $this->sent = [];
        (new \CleatSquad\LlmRouter\Driver\GroqDriver($this->http($answer)))->chat($request('openai/gpt-oss-120b'));
        $oss = $this->lastPayload();
        $this->assertSame('high', $oss['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_format', $oss);
    }

    public function testGroqLlamaModelsAreMarkedAsNonReasoning(): void
    {
        $answer = new Response(200, [], json_encode([
            'model' => 'm',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => [],
        ], JSON_THROW_ON_ERROR));

        // Groq documents no reasoning parameters for the instruction-tuned
        // Llama models.
        $this->expectException(UnsupportedReasoningException::class);
        (new \CleatSquad\LlmRouter\Driver\GroqDriver($this->http($answer)))->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'llama-3.3-70b-versatile',
            reasoningEffort: ReasoningEffort::High,
        ));
    }

    public function testNotAskingForReasoningWorksOnANonReasoningModel(): void
    {
        $driver = new OpenAiDriver($this->http($this->openAiAnswer()));

        $response = $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gpt-4o',
        ));

        $this->assertSame('ok', $response->content);
        $this->assertArrayNotHasKey('reasoning_effort', $this->lastPayload());
    }

    public function testEffortNoneIsNotAReasoningRequestAndPassesTheGuard(): void
    {
        $driver = new OpenAiDriver($this->http($this->openAiAnswer()));

        // "Don't reason" is a legitimate thing to say to a model that can't.
        $driver->chat(new LLMRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gpt-4o',
            reasoningEffort: ReasoningEffort::None,
        ));

        $this->assertSame('none', $this->lastPayload()['reasoning_effort']);
    }
}
