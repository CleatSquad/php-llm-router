<?php

declare(strict_types=1);

namespace Concio\LlmRouter\Driver;

use Concio\LlmRouter\Contract\Driver\LLMDriverInterface;
use Concio\LlmRouter\DTO\CostEstimate;
use Concio\LlmRouter\DTO\HealthStatus;
use Concio\LlmRouter\DTO\HealthState;
use Concio\LlmRouter\DTO\LLMRequest;
use Concio\LlmRouter\DTO\LLMResponse;
use Concio\LlmRouter\Enum\DriverType;
use Concio\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * LLM driver for a local Ollama instance.
 */
class OllamaDriver implements LLMDriverInterface
{
    private string $ollamaUrl;

    public function __construct(
        private HttpClient $httpClient,
        string $ollamaUrl = 'http://ollama:11434',
        private readonly string $ollamaModel = 'llama3',
        private readonly float $localLlmTimeout = 15.0
    ) {
        $this->ollamaUrl = rtrim($ollamaUrl, '/');
    }

    public function getId(): string
    {
        return 'ollama';
    }

    public function getName(): string
    {
        return 'Ollama Local';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->getClient()->get($this->ollamaUrl . '/api/tags', [
                'timeout' => 3.0,
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
            $response = $this->httpClient->getClient()->get($this->ollamaUrl . '/api/tags', [
                'timeout' => 3.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Ollama is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Ollama health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Ollama connection error: ' . $e->getMessage(),
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
            'url' => $this->ollamaUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'chat' => true,
                'streaming' => true,
                'tools' => false,
                'vision' => false,
            ]
        ];
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $model = $request->model ?? $this->ollamaModel;

        // Normalize model name (remove provider prefix if present)
        $modelClean = str_contains($model, '/') ? explode('/', $model)[1] : $model;

        // Resilience: fallback or smart match with locally available models
        $localModels = $this->getModels();
        if (!empty($localModels)) {
            if (in_array($modelClean, $localModels, true)) {
                $model = $modelClean;
            } else {
                $matchedModel = null;

                // 0. Prioritize the default local model if it matches the request (case-insensitive)
                $defaultModel = $this->ollamaModel;
                $defaultModelClean = str_contains($defaultModel, '/') ? explode('/', $defaultModel)[1] : $defaultModel;
                if (in_array($defaultModelClean, $localModels, true) &&
                    (str_contains(strtolower($defaultModelClean), strtolower($modelClean)) ||
                     str_contains(strtolower($modelClean), strtolower($defaultModelClean)))) {
                    $matchedModel = $defaultModelClean;
                }

                // 1. Try to find a local model that contains the requested clean model name (excluding embedding models), case-insensitive
                if ($matchedModel === null) {
                    foreach ($localModels as $localModel) {
                        if (str_contains($localModel, 'embed') || str_contains($localModel, 'nomic')) {
                            continue;
                        }
                        if (str_contains(strtolower($localModel), strtolower($modelClean))) {
                            $matchedModel = $localModel;
                            break;
                        }
                    }
                }

                // 2. If not found, try to find any llama or lama model if the requested model contains "llama" or "lama"
                if ($matchedModel === null && (str_contains(strtolower($modelClean), 'llama') || str_contains(strtolower($modelClean), 'lama'))) {
                    foreach ($localModels as $localModel) {
                        $localLower = strtolower($localModel);
                        if (str_contains($localLower, 'llama') || str_contains($localLower, 'lama')) {
                            $matchedModel = $localModel;
                            break;
                        }
                    }
                }

                // 3. If still not found, fallback to the first non-embedding model
                if ($matchedModel === null) {
                    foreach ($localModels as $localModel) {
                        if (!str_contains($localModel, 'embed') && !str_contains($localModel, 'nomic')) {
                            $matchedModel = $localModel;
                            break;
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
            $payload['options']['temperature'] = $request->temperature;
        }

        if ($request->maxTokens !== null) {
            $payload['options']['num_predict'] = $request->maxTokens;
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->ollamaUrl . '/api/chat', [
                'json' => $payload,
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('Ollama request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Ollama returned invalid JSON payload: ' . $contents);
        }

        $content = $data['message']['content'] ?? '';
        $finishReason = $data['done_reason'] ?? 'stop';

        $promptTokens = (int) ($data['prompt_eval_count'] ?? 0);
        $completionTokens = (int) ($data['eval_count'] ?? 0);
        $totalTokens = $promptTokens + $completionTokens;

        // Custom latency from Ollama (in nanoseconds) if available
        if (isset($data['total_duration'])) {
            $latencyMs = (int) ($data['total_duration'] / 1000000);
        }

        return new LLMResponse(
            content: $content,
            model: $payload['model'],
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: 0.0, // Local execution is free
            latencyMs: $latencyMs,
            toolCalls: null,
            finishReason: $finishReason
        );
    }

    public function stream(LLMRequest $request): Generator
    {
        yield '';
        throw new RuntimeException('Streaming not implemented yet in OllamaDriver.');
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        try {
            $response = $this->httpClient->getClient()->get($this->ollamaUrl . '/api/tags', [
                'timeout' => 2.0,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];
            if (isset($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $modelInfo) {
                    if (isset($modelInfo['name'])) {
                        $models[] = (string) $modelInfo['name'];
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
        return false;
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
        return CostEstimate::free();
    }
}
