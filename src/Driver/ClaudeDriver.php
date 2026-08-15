<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Driver\Concern\NormalizesVisionContent;
use CleatSquad\LlmRouter\Driver\Concern\ResolvesPricedModel;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthState;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Enum\ReasoningEffort;
use CleatSquad\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * LLM driver for the Anthropic Claude API (Messages API).
 */
class ClaudeDriver implements LLMDriverInterface
{
    use NormalizesVisionContent;

    private const ANTHROPIC_VERSION = '2023-06-01';

    use ResolvesPricedModel;

    /** Used when a request names no model at all — a caller declining to choose. */
    private const DEFAULT_MODEL = 'claude-sonnet-5';

    /** @var array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}> Cost per 1k tokens in USD */
    private const PRICING = [
        // USD per 1k tokens. Verified against Anthropic's published per-million
        // rates: divide those by 1000. Every model here reasons — Anthropic's
        // current line is thinking-capable throughout — so none carries an
        // explicit 'reasoning' => false.
        'claude-fable-5' => ['input' => 0.010, 'output' => 0.050, 'thinkingAlwaysOn' => true],
        'claude-mythos-5' => ['input' => 0.010, 'output' => 0.050, 'thinkingAlwaysOn' => true],
        'claude-opus-5' => ['input' => 0.005, 'output' => 0.025],
        'claude-opus-4-8' => ['input' => 0.005, 'output' => 0.025],
        'claude-opus-4-7' => ['input' => 0.005, 'output' => 0.025],
        'claude-opus-4-6' => ['input' => 0.005, 'output' => 0.025],
        'claude-sonnet-5' => ['input' => 0.003, 'output' => 0.015],
        'claude-sonnet-4-6' => ['input' => 0.003, 'output' => 0.015],
        'claude-haiku-4-5' => ['input' => 0.001, 'output' => 0.005],
    ];

    use Concern\HandlesHttpRateLimit;

    private string $anthropicUrl;
    private string $anthropicApiKey;

    /**
     * @param array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}> $extraModelPricing
     *   Pricing per 1k tokens for models this release predates, merged over the
     *   shipped table. Without an entry here, an unknown model is rejected
     *   rather than silently served by the default one.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $anthropicUrl = 'https://api.anthropic.com/v1',
        string $anthropicApiKey = '',
        private readonly float $localLlmTimeout = 30.0,
        array $extraModelPricing = [],
    ) {
        $this->extraModelPricing = $extraModelPricing;
        $this->anthropicUrl = rtrim($anthropicUrl, '/');
        $this->anthropicApiKey = $anthropicApiKey;
    }

    public function getId(): string
    {
        return 'claude';
    }

    public function getName(): string
    {
        return 'Claude Direct (Anthropic)';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->anthropicApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->anthropicApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'Anthropic API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->anthropicUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Claude API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Claude health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Claude connection error: ' . $e->getMessage(),
                new DateTimeImmutable()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'url' => $this->anthropicUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'chat' => true,
                'streaming' => true,
                'tools' => true,
                'vision' => true,
            ]
        ];
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $model = $this->resolveModel($request->model);
        [$system, $messages] = $this->splitSystemMessages($request->messages);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? 4096,
            'stream' => $request->stream,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        $payload = $this->applyReasoning($payload, $request);

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->anthropicUrl . '/messages', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->handleHttpRateLimit($e, 'Claude');
            throw new RuntimeException('Claude request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Claude request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Claude returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['type']) && $data['type'] === 'error') {
            $message = $data['error']['message'] ?? 'Unknown Claude API error';
            throw new RuntimeException('Claude API error: ' . $message);
        }

        $textContent = '';
        $reasoning = '';
        $reasoningSignature = null;
        $toolCalls = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $textContent .= $block['text'] ?? '';
            } elseif (($block['type'] ?? null) === 'thinking') {
                // With display "omitted" the block still arrives, carrying a
                // signature but an empty thinking field. The signature is what
                // has to survive to the next turn either way.
                $reasoning .= $block['thinking'] ?? '';
                $reasoningSignature = $block['signature'] ?? $reasoningSignature;
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'] ?? '',
                        'arguments' => json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }

        $promptTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $completionTokens = (int) ($data['usage']['output_tokens'] ?? 0);
        $totalTokens = $promptTokens + $completionTokens;

        $pricing = $this->pricingFor($model);
        $costUsd = (($promptTokens * $pricing['input']) + ($completionTokens * $pricing['output'])) / 1000;

        return new LLMResponse(
            content: $textContent,
            model: $data['model'] ?? $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            toolCalls: !empty($toolCalls) ? $toolCalls : null,
            finishReason: $data['stop_reason'] ?? 'stop',
            reasoning: $reasoning !== '' ? $reasoning : null,
            reasoningTokens: isset($data['usage']['output_tokens_details']['thinking_tokens'])
                ? (int) $data['usage']['output_tokens_details']['thinking_tokens']
                : null,
            reasoningSignature: $reasoningSignature,
        );
    }

    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);
        [$system, $messages] = $this->splitSystemMessages($request->messages);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? 4096,
            'stream' => true,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        $payload = $this->applyReasoning($payload, $request);

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->anthropicUrl . '/messages', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->handleHttpRateLimit($e, 'Claude');
            throw new RuntimeException('Claude stream request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Claude stream request failed: ' . $e->getMessage(), 0, $e);
        }

        // Anthropic's SSE framing differs from OpenAI's: "event: <type>" + "data: {json}" lines;
        // text arrives via content_block_delta/text_delta, tool calls via content_block_start
        // (id, name) then content_block_delta/input_json_delta chunks, re-shaped here into the
        // same {id, type: "function", function: {name, arguments}} array chat() returns.
        $body = $response->getBody();
        $buffer = '';
        $toolCalls = [];

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = json_decode(trim(substr($line, 5)), true);
                if (!is_array($data)) {
                    continue;
                }

                $eventType = $data['type'] ?? '';
                $index = $data['index'] ?? 0;

                if ($eventType === 'content_block_start') {
                    $block = $data['content_block'] ?? [];
                    if (($block['type'] ?? '') === 'tool_use') {
                        $toolCalls[$index] = [
                            'id' => $block['id'] ?? '',
                            'type' => 'function',
                            'function' => ['name' => $block['name'] ?? '', 'arguments' => ''],
                        ];
                    }
                }

                if ($eventType === 'content_block_delta') {
                    $deltaType = $data['delta']['type'] ?? '';

                    if ($deltaType === 'thinking_delta') {
                        // Never yielded: the generator's values are the visible
                        // answer, and an application echoing them must not end
                        // up printing the model's scratch work to a user.
                        $request->emitReasoning((string) ($data['delta']['thinking'] ?? ''));
                    } elseif ($deltaType === 'text_delta') {
                        $text = $data['delta']['text'] ?? '';
                        if ($text !== '') {
                            yield $text;
                        }
                    } elseif ($deltaType === 'input_json_delta' && isset($toolCalls[$index])) {
                        $toolCalls[$index]['function']['arguments'] .= $data['delta']['partial_json'] ?? '';
                    }
                }

                if ($eventType === 'message_stop') {
                    return self::orderedToolCalls($toolCalls);
                }
            }
        }

        return self::orderedToolCalls($toolCalls);
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return array_keys($this->modelPricing());
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function supportsReasoning(): bool
    {
        return true;
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        $model = $this->resolveModel($request->model);
        $inputTokens = $request->estimateInputTokens();
        $pricing = $this->pricingFor($model);

        $estimatedOutputTokens = $request->maxTokens ?? 200;
        $estimatedTokens = $inputTokens + $estimatedOutputTokens;
        $estimatedCostUsd = (($inputTokens * $pricing['input']) + ($estimatedOutputTokens * $pricing['output'])) / 1000;

        return new CostEstimate($pricing['input'], $pricing['output'], $estimatedTokens, $estimatedCostUsd);
    }


    /**
     * Splits OpenAI-style messages (may include a "system" role) into Anthropic's system prompt + message list.
     * Non-system content is translated via convertContentForClaude() — a 2026-08-01 fix, since passing OpenAI's vision shape straight through silently broke every vision request to Claude.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{0: string, 1: array<int, array{role: string, content: mixed}>}
     */

    /**
     * Flattens streamed tool calls in content-block index order.
     *
     * Sorted, not merely re-indexed: array_values() preserves insertion order,
     * which is arrival order, and a block opened out of sequence would pair
     * each id with another call's arguments.
     *
     * @param array<int, array{id: string, type: string, function: array{name: string, arguments: string}}> $toolCalls
     * @return array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null
     */
    private static function orderedToolCalls(array $toolCalls): ?array
    {
        if ($toolCalls === []) {
            return null;
        }

        ksort($toolCalls);

        return array_values($toolCalls);
    }

    /**
     * Adds Anthropic's thinking configuration to a payload.
     *
     * Uses adaptive thinking (`thinking: {type: "adaptive"}` plus
     * `output_config.effort`), not the older `{type: "enabled", budget_tokens}`
     * manual mode: that mode is deprecated on Claude 4.6 and returns a 400 on
     * Claude 4.7 and later, so a budget-based implementation would fail on
     * every current model.
     *
     * `display` matters more than it looks. On the newest models it defaults to
     * "omitted", meaning thinking blocks come back with an empty `thinking`
     * field — billed the same, just unreadable. Asking for the trace therefore
     * has to opt into "summarized" explicitly.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyReasoning(array $payload, LLMRequest $request): array
    {
        if ($request->reasoningEffort === null) {
            return $payload; // Leave the model's own default alone.
        }

        if ($request->reasoningEffort === ReasoningEffort::None) {
            // Claude Fable 5 and Mythos 5 always think: sending
            // `thinking: {type: "disabled"}` to them is a 400, so the only way
            // to express "don't think" there is to say nothing and let the
            // model's own default stand.
            if (($this->pricingFor($payload['model'])['thinkingAlwaysOn'] ?? false) !== true) {
                $payload['thinking'] = ['type' => 'disabled'];
            }

            return $payload;
        }

        $payload['thinking'] = [
            'type' => 'adaptive',
            'display' => $request->includeReasoning ? 'summarized' : 'omitted',
        ];
        $payload['output_config'] = ['effort' => $request->reasoningEffort->clampTo([
            ReasoningEffort::Low,
            ReasoningEffort::Medium,
            ReasoningEffort::High,
            ReasoningEffort::XHigh,
            ReasoningEffort::Max,
        ])->value];

        return $payload;
    }

    /**
     * Splits the system prompt out and converts the rest to Anthropic's shape.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function splitSystemMessages(array $messages): array
    {
        $system = [];
        $rest = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                $content = $message['content'] ?? '';
                $system[] = is_string($content) ? $content : $this->extractVisionParts($content)[0];
            } else {
                $content = $this->convertContentForClaude($message['content'] ?? '');

                // Anthropic requires a thinking-enabled assistant turn to begin
                // with its thinking block, and rejects a replayed trace whose
                // signature is missing. LLMResponse::toMessage() carries both.
                $reasoning = $message['reasoning'] ?? null;
                $signature = $message['reasoning_signature'] ?? null;
                if ($message['role'] === 'assistant' && is_string($reasoning) && is_string($signature)) {
                    $thinkingBlock = ['type' => 'thinking', 'thinking' => $reasoning, 'signature' => $signature];
                    $content = is_array($content)
                        ? array_merge([$thinkingBlock], $content)
                        : [$thinkingBlock, ['type' => 'text', 'text' => (string) $content]];
                }

                $rest[] = [
                    'role' => $message['role'],
                    'content' => $content,
                ];
            }
        }

        return [implode("\n\n", $system), $rest];
    }

    /**
     * Translates OpenAI-shaped vision content into Anthropic's content-block array; plain strings pass through unchanged.
     * Shared OpenAI-shape parsing lives in NormalizesVisionContent::extractVisionParts().
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function convertContentForClaude(mixed $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }

        [$text, $images] = $this->extractVisionParts($content);
        $blocks = [];

        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($images as $image) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mediaType'],
                    'data' => $image['data'],
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->anthropicApiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ];
    }
}
