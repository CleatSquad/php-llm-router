<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * LLM driver for a LiteLLM proxy (itself fronting many providers/models).
 */
class LiteLLMDriver implements LLMDriverInterface
{
    private string $liteLlmUrl;
    private string $liteLlmKey;

    public function __construct(
        private HttpClient $httpClient,
        string $liteLlmUrl = 'http://litellm:4000',
        string $liteLlmKey = 'sk-local-master-key',
        private readonly float $localLlmTimeout = 15.0
    ) {
        $this->liteLlmUrl = rtrim($liteLlmUrl, '/');
        $this->liteLlmKey = $liteLlmKey;
    }

    public function getId(): string
    {
        return 'litellm';
    }

    public function getName(): string
    {
        return 'LiteLLM Proxy';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->getClient()->get($this->liteLlmUrl . '/v1/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function healthCheck(): HealthStatus
    {
        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->liteLlmUrl . '/v1/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'LiteLLM is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'LiteLLM health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'LiteLLM connection error: ' . $e->getMessage(),
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
            'url' => $this->liteLlmUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'chat' => true,
                'streaming' => true,
                'tools' => true,
                'vision' => false,
            ]
        ];
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        // preferQuality signals the caller specifically wants a paid/capable
        // model instead of a local one — defaulting to 'llama3' here when no
        // explicit model is given would defeat that. Adjust the two default
        // model ids below to match your own litellm/config.yaml routes.
        $model = $request->model ?? ($request->preferQuality ? 'groq' : 'llama3');

        // Normalize model name (remove provider prefix if present)
        $modelClean = str_contains($model, '/') ? explode('/', $model)[1] : $model;

        // Resilience: fallback or smart match with models registered in LiteLLM
        $registeredModels = $this->getModels();
        if (!empty($registeredModels)) {
            if (in_array($model, $registeredModels, true)) {
                // Keep exact model as requested if it matches
            } elseif (in_array($modelClean, $registeredModels, true)) {
                $model = $modelClean;
            } else {
                $matchedModel = null;
                // 1. Try to find a registered model that contains the requested clean model name (excluding embedding models), case-insensitive
                foreach ($registeredModels as $regModel) {
                    if (str_contains($regModel, 'embed') || str_contains($regModel, 'nomic')) {
                        continue;
                    }
                    if (str_contains(strtolower($regModel), strtolower($modelClean))) {
                        $matchedModel = $regModel;
                        break;
                    }
                }

                // 2. If not found, try to find any llama or lama model if the requested model contains "llama" or "lama"
                if ($matchedModel === null && (str_contains(strtolower($modelClean), 'llama') || str_contains(strtolower($modelClean), 'lama'))) {
                    foreach ($registeredModels as $regModel) {
                        $regLower = strtolower($regModel);
                        if (str_contains($regLower, 'llama') || str_contains($regLower, 'lama')) {
                            $matchedModel = $regModel;
                            break;
                        }
                    }
                }

                // 3. If still not found, fallback to the default model if registered, or the first non-embedding model
                if ($matchedModel === null) {
                    $defaultModel = $request->preferQuality ? 'groq' : 'llama3';
                    if (in_array($defaultModel, $registeredModels, true)) {
                        $matchedModel = $defaultModel;
                    } else {
                        foreach ($registeredModels as $regModel) {
                            if (!str_contains($regModel, 'embed') && !str_contains($regModel, 'nomic')) {
                                $matchedModel = $regModel;
                                // Prefer a local-like model first if possible
                                if (str_contains($regModel, 'llama') || str_contains($regModel, 'gemma') || str_contains($regModel, 'deepseek')) {
                                    break;
                                }
                            }
                        }
                    }
                }

                if ($matchedModel !== null) {
                    $model = $matchedModel;
                }
            }
        }

        $payload = [
            'model' => $model,
            'messages' => $request->messages,
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
            $response = $this->httpClient->getClient()->post($this->liteLlmUrl . '/v1/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('LiteLLM request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('LiteLLM returned invalid JSON payload: ' . $contents);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';
        $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? null;

        $promptTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($data['usage']['completion_tokens'] ?? 0);
        $totalTokens = (int) ($data['usage']['total_tokens'] ?? 0);
        $costUsd = (float) ($data['usage']['cost'] ?? 0.0);

        return new LLMResponse(
            content: $content,
            model: $payload['model'],
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            toolCalls: $toolCalls,
            finishReason: $finishReason
        );
    }

    public function stream(LLMRequest $request): Generator
    {
        yield '';
        throw new RuntimeException('Streaming not implemented yet in LiteLLMDriver.');
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        try {
            $response = $this->httpClient->getClient()->get($this->liteLlmUrl . '/v1/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 5.0,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $modelInfo) {
                    if (isset($modelInfo['id'])) {
                        $models[] = (string) $modelInfo['id'];
                    }
                }
            }
            return $models;
        } catch (\Exception $e) {
            return [];
        }
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
        return false;
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        $model = $request->model ?? 'llama3';
        $inputTokens = $request->estimateInputTokens();

        // Default local model rates (free)
        $inputCostPer1k = 0.0;
        $outputCostPer1k = 0.0;

        if (str_contains($model, 'gpt-4o-mini')) {
            $inputCostPer1k = 0.00015;
            $outputCostPer1k = 0.0006;
        } elseif (str_contains($model, 'gpt-4o')) {
            $inputCostPer1k = 0.005;
            $outputCostPer1k = 0.015;
        } elseif (str_contains($model, 'claude-3-5-sonnet')) {
            $inputCostPer1k = 0.003;
            $outputCostPer1k = 0.015;
        } elseif (str_contains($model, 'claude-3-haiku')) {
            $inputCostPer1k = 0.00025;
            $outputCostPer1k = 0.00125;
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
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->liteLlmKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->liteLlmKey;
        }

        return $headers;
    }
}
