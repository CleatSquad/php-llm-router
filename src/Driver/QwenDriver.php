<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCapabilitiesInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface;
use CleatSquad\LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use CleatSquad\LlmRouter\Driver\Concern\ReplaysChatCompletionReasoning;
use CleatSquad\LlmRouter\Driver\Concern\ResolvesPricedModel;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthState;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * Direct Qwen (Alibaba) API driver — OpenAI-compatible chat completions
 *.
 *
 * PRICING starts empty: no Qwen (Alibaba) rate has been verified against the vendor's
 * published pricing yet. An empty table means every model is rejected by
 * ResolvesPricedModel until a caller registers one through
 * $extraModelPricing with a confirmed rate — never a guessed one.
 */
class QwenDriver implements LLMDriverInterface, ModelCatalogueInterface, ModelCapabilitiesInterface
{
    use ResolvesPricedModel;

    use ParsesChatCompletionSse;
    use ReplaysChatCompletionReasoning;

    /** Used when a request names no model at all — a caller declining to choose. */
    private const DEFAULT_MODEL = 'qwen3-max';

    /**
     * @var array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool, vision?: bool, tools?: bool, context?: int}>
     */
    private const PRICING = [];

    private string $qwenUrl;
    private string $qwenApiKey;

    /**
     * @param array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool, vision?: bool, tools?: bool, context?: int}> $extraModelPricing
     *   Pricing per 1k tokens for models Qwen (Alibaba) serves, keyed by model_id — this
     *   package ships none for Qwen (Alibaba) yet (see class docblock), so a caller must
     *   register at least one entry here (with a verified rate) before any
     *   request naming a model can succeed.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $qwenUrl = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
        string $qwenApiKey = '',
        private readonly float $localLlmTimeout = 30.0,
        array $extraModelPricing = [],
    ) {
        $this->extraModelPricing = $extraModelPricing;
        $this->qwenUrl = rtrim($qwenUrl, '/');
        $this->qwenApiKey = $qwenApiKey;
    }

    public function getId(): string
    {
        return 'qwen';
    }

    public function getName(): string
    {
        return 'Qwen (Alibaba)';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->qwenApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->qwenApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'Qwen (Alibaba) API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->qwenUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Qwen (Alibaba) API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Qwen (Alibaba) health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Qwen (Alibaba) connection error: ' . $e->getMessage(),
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
            'url' => $this->qwenUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'chat' => true,
                'streaming' => true,
                'tools' => true,
                'vision' => false,
            ],
        ];
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning_content'),
            'stream' => $request->stream,
        ];

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->qwenUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('Qwen (Alibaba) request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Qwen (Alibaba) returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown Qwen (Alibaba) API error';
            throw new RuntimeException('Qwen (Alibaba) API error: ' . $message);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $reasoning = $data['choices'][0]['message']['reasoning_content'] ?? null;
        if (is_array($content)) {
            $textParts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $textParts[] = (string) $part['text'];
                } elseif (is_string($part)) {
                    $textParts[] = $part;
                }
            }
            $content = $textParts !== [] ? implode('', $textParts) : json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        $content = (string) $content;
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';
        $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? null;

        $promptTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($data['usage']['completion_tokens'] ?? 0);
        $totalTokens = (int) ($data['usage']['total_tokens'] ?? 0);

        $pricing = $this->pricingFor($model);
        $costUsd = (($promptTokens * $pricing['input']) + ($completionTokens * $pricing['output'])) / 1000;

        return new LLMResponse(
            content: $content,
            model: $data['model'] ?? $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            reasoning: is_string($reasoning) && $reasoning !== '' ? $reasoning : null,
        );
    }

    /**
     * @return Generator<int, string, mixed, (array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|array{tool_calls: array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost_usd: float}|null)>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning_content'),
            'stream' => true,
            'stream_options' => [
                'include_usage' => true,
            ],
        ];

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->qwenUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException('Qwen (Alibaba) stream request failed: ' . $e->getMessage(), 0, $e);
        }

        $result = yield from self::readChatCompletionSse($response->getBody(), $request);
        if ($result === null) {
            return null;
        }

        $toolCalls = $result['tool_calls'] ?? null;
        $usage = $result['usage'] ?? null;

        if ($usage === null) {
            return $toolCalls;
        }

        $pricing = $this->pricingFor($model);
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($promptTokens + $completionTokens));
        $costUsd = (($promptTokens * $pricing['input']) + ($completionTokens * $pricing['output'])) / 1000;

        return [
            'tool_calls' => $toolCalls,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $costUsd,
        ];
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
        return false;
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
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->qwenApiKey,
        ];
    }
}
