<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use DateTimeImmutable;
use Generator;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use LlmRouter\Driver\Concern\ReplaysChatCompletionReasoning;
use LlmRouter\Driver\Concern\ResolvesPricedModel;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Enum\ReasoningEffort;
use LlmRouter\Http\HttpClient;
use RuntimeException;

/**
 * Direct OpenAI Chat Completions API driver.
 */
class OpenAiDriver implements LLMDriverInterface
{
    use ResolvesPricedModel;

    /** Used when a request names no model at all — a caller declining to choose. */
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    private const PRICING = [
        'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
        'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
    ];

    use ParsesChatCompletionSse;
    use ReplaysChatCompletionReasoning;

    private string $openAiUrl;
    private string $openAiApiKey;

    /**
     * @param array<string, array{input: float, output: float}> $extraModelPricing
     *   Pricing per 1k tokens for models this release predates, merged over the
     *   shipped table. Without an entry here, an unknown model is rejected
     *   rather than silently served by the default one.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $openAiUrl = 'https://api.openai.com/v1',
        string $openAiApiKey = '',
        private readonly float $localLlmTimeout = 30.0,
        array $extraModelPricing = [],
    ) {
        $this->extraModelPricing = $extraModelPricing;
        $this->openAiUrl = rtrim($openAiUrl, '/');
        $this->openAiApiKey = $openAiApiKey;
    }

    public function getId(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        return 'OpenAI Direct (ChatGPT)';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->openAiApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->openAiApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'OpenAI API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->openAiUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'OpenAI API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'OpenAI health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'OpenAI connection error: ' . $e->getMessage(),
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
            'url' => $this->openAiUrl,
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

        $payload = [
            'model' => $model,
            'messages' => self::withoutReasoningKeys($request->messages),
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

        // OpenAI spends reasoning tokens but never returns the trace, so
        // $includeReasoning has nothing to act on here — only the effort does.
        if ($request->reasoningEffort !== null) {
            $payload['reasoning_effort'] = $request->reasoningEffort->value;
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('OpenAI returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown OpenAI API error';
            throw new RuntimeException('OpenAI API error: ' . $message);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $textParts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $textParts[] = (string)$part['text'];
                } elseif (is_string($part)) {
                    $textParts[] = $part;
                }
            }
            $content = !empty($textParts) ? implode('', $textParts) : json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        $content = (string)$content;
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
            finishReason: $finishReason
        );
    }

    /**
     * @return Generator<int, string, mixed, void>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'messages' => self::withoutReasoningKeys($request->messages),
            'stream' => true,
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

        // OpenAI spends reasoning tokens but never returns the trace, so
        // $includeReasoning has nothing to act on here — only the effort does.
        if ($request->reasoningEffort !== null) {
            $payload['reasoning_effort'] = $request->reasoningEffort->value;
        }

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI stream request failed: ' . $e->getMessage(), 0, $e);
        }

        return yield from self::readChatCompletionSse($response->getBody(), $request);
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
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->openAiApiKey,
        ];
    }
}
