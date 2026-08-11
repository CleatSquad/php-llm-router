<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use DateTimeImmutable;
use Generator;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use LlmRouter\Driver\Concern\ReplaysChatCompletionReasoning;
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
 * LLM driver for Moonshot AI's Kimi models.
 */
class KimiDriver implements LLMDriverInterface
{
    use ParsesChatCompletionSse;
    use ReplaysChatCompletionReasoning;

    private string $moonshotUrl;
    private string $moonshotApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $moonshotUrl = 'https://api.moonshot.cn/v1',
        string $moonshotApiKey = '',
        private readonly float $localLlmTimeout = 30.0,
        private readonly string $moonshotModel = 'moonshot-v1-8k'
    ) {
        $this->moonshotUrl = rtrim($moonshotUrl, '/');
        $this->moonshotApiKey = $moonshotApiKey;
    }

    public function getId(): string
    {
        return 'kimi';
    }

    public function getName(): string
    {
        return 'Kimi Direct (Moonshot AI)';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->moonshotApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->moonshotApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'Moonshot API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->moonshotUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Kimi API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Kimi health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Kimi connection error: ' . $e->getMessage(),
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
            'url' => $this->moonshotUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'chat' => true,
                'streaming' => true,
                'tools' => true,
                'vision' => false,
            ]
        ];
    }

    /**
     * Moonshot's two platforms (api.moonshot.cn vs .ai) have disjoint, server-side-mutable catalogs, so an explicit model is trusted as-is rather than validated against a static whitelist. Only a null request falls back to the default.
     */
    private function resolveModel(?string $requestedModel): string
    {
        $model = $requestedModel ?? $this->moonshotModel;

        // Strip provider prefix if present
        if (str_contains($model, '/')) {
            $model = explode('/', $model)[1];
        }

        return $model;
    }

    /**
     * K2 reasoning models (kimi-k2*) reject any temperature but exactly 1 with a 400 error, so it's overridden here rather than surfaced to every caller.
     */
    private function resolveTemperature(string $model, float $temperature): float
    {
        if (str_starts_with($model, 'kimi-k2')) {
            return 1.0;
        }

        return $temperature;
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
            $payload['temperature'] = $this->resolveTemperature($model, $request->temperature);
        }

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        // Moonshot has no switch: reasoning is a property of the k2-thinking
        // family, so an effort here only documents intent. The trace still
        // comes back, and still has to be replayed on the next turn.

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->moonshotUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('Kimi request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Kimi returned invalid JSON payload: ' . $contents);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $reasoning = $data['choices'][0]['message']['reasoning_content'] ?? null;
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

        // Moonshot API does not specify cost in usage payload, so we estimate it using our own logic
        $costEstimate = $this->estimateCost(new LLMRequest(
            messages: $request->messages,
            model: $model,
            temperature: $request->temperature,
            maxTokens: $completionTokens,
            tools: $request->tools
        ));
        $costUsd = $costEstimate->estimatedCostUsd;

        return new LLMResponse(
            content: $content,
            model: $payload['model'],
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
     * @return Generator<int, string, mixed, void>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning_content'),
            'stream' => true,
        ];

        if ($request->temperature !== null) {
            $payload['temperature'] = $this->resolveTemperature($model, $request->temperature);
        }

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        // Moonshot has no switch: reasoning is a property of the k2-thinking
        // family, so an effort here only documents intent. The trace still
        // comes back, and still has to be replayed on the next turn.

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->moonshotUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException('Kimi stream request failed: ' . $e->getMessage(), 0, $e);
        }

        return yield from self::readChatCompletionSse($response->getBody(), $request);
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return ['moonshot-v1-8k', 'moonshot-v1-32k', 'moonshot-v1-128k'];
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
        $model = $request->model ?? 'moonshot-v1-8k';
        $inputTokens = $request->estimateInputTokens();

        $inputCostPer1k = 0.0016;
        $outputCostPer1k = 0.0016;
        if (str_contains($model, '32k')) {
            $inputCostPer1k = 0.0033;
            $outputCostPer1k = 0.0033;
        } elseif (str_contains($model, '128k')) {
            $inputCostPer1k = 0.0083;
            $outputCostPer1k = 0.0083;
        }

        $estimatedOutputTokens = $request->maxTokens ?? 200;
        $estimatedTokens = $inputTokens + $estimatedOutputTokens;
        $estimatedCostUsd = (($inputTokens * $inputCostPer1k) + ($estimatedOutputTokens * $outputCostPer1k)) / 1000;

        return new CostEstimate($inputCostPer1k, $outputCostPer1k, $estimatedTokens, $estimatedCostUsd);
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->moonshotApiKey,
        ];
    }
}
