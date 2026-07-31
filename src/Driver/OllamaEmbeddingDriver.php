<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Contract\Driver\EmbeddingDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\EmbeddingRequest;
use LlmRouter\DTO\EmbeddingResponse;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use RuntimeException;

/**
 * Embedding driver for a local Ollama instance (POST /api/embed — the
 * current batch-capable endpoint, not the older single-prompt /api/embeddings).
 */
class OllamaEmbeddingDriver implements EmbeddingDriverInterface
{
    private string $ollamaUrl;

    public function __construct(
        private HttpClient $httpClient,
        string $ollamaUrl = 'http://ollama:11434',
        private readonly string $ollamaModel = 'nomic-embed-text',
        private readonly float $localLlmTimeout = 15.0
    ) {
        $this->ollamaUrl = rtrim($ollamaUrl, '/');
    }

    public function getId(): string
    {
        return 'ollama-embedding';
    }

    public function getName(): string
    {
        return 'Ollama Local Embeddings';
    }

    public function getType(): DriverType
    {
        return DriverType::EMBEDDING;
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
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return $response->getStatusCode() === 200
                ? new HealthStatus(HealthState::HEALTHY, $latencyMs, 'Ollama is operational', new DateTimeImmutable())
                : new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'Ollama health check returned HTTP ' . $response->getStatusCode(), new DateTimeImmutable());
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'Ollama connection error: ' . $e->getMessage(), new DateTimeImmutable());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return ['url' => $this->ollamaUrl, 'capabilities' => ['embeddings' => true]];
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $model = $request->model ?? $this->ollamaModel;

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->ollamaUrl . '/api/embed', [
                'json' => ['model' => $model, 'input' => $request->input],
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            throw new RuntimeException('Ollama embeddings request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Ollama returned invalid JSON payload');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('Ollama API error: ' . $data['error']);
        }

        return new EmbeddingResponse(
            embeddings: $data['embeddings'] ?? [],
            model: $data['model'] ?? $model,
            promptTokens: (int) ($data['prompt_eval_count'] ?? 0),
            totalTokens: (int) ($data['prompt_eval_count'] ?? 0),
            costUsd: 0.0,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return [$this->ollamaModel];
    }

    public function estimateCost(EmbeddingRequest $request): CostEstimate
    {
        $chars = array_sum(array_map('mb_strlen', $request->input));
        $estimatedTokens = (int) ceil($chars / 4);

        return new CostEstimate(
            inputCostPer1k: 0.0,
            outputCostPer1k: 0.0,
            estimatedTokens: $estimatedTokens,
            estimatedCostUsd: 0.0,
        );
    }
}
