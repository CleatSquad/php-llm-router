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
 * Direct Google Gemini Embeddings API driver (batchEmbedContents) — free
 * of charge as of writing, hence no PRICING table.
 */
class GeminiEmbeddingDriver implements EmbeddingDriverInterface
{
    private const MODELS = ['gemini-embedding-001', 'gemini-embedding-2'];

    private string $geminiUrl;
    private string $geminiApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta',
        string $geminiApiKey = '',
        private readonly float $localLlmTimeout = 30.0
    ) {
        $this->geminiUrl = rtrim($geminiUrl, '/');
        $this->geminiApiKey = $geminiApiKey;
    }

    public function getId(): string
    {
        return 'gemini-embedding';
    }

    public function getName(): string
    {
        return 'Google Gemini Embeddings';
    }

    public function getType(): DriverType
    {
        return DriverType::EMBEDDING;
    }

    public function isAvailable(): bool
    {
        return !empty($this->geminiApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->geminiApiKey)) {
            return new HealthStatus(HealthState::UNHEALTHY, 0, 'Gemini API Key is not set', new DateTimeImmutable());
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->geminiUrl . '/models', [
                'query' => ['key' => $this->geminiApiKey],
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return $response->getStatusCode() === 200
                ? new HealthStatus(HealthState::HEALTHY, $latencyMs, 'Gemini API is operational', new DateTimeImmutable())
                : new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'Gemini health check returned HTTP ' . $response->getStatusCode(), new DateTimeImmutable());
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'Gemini connection error: ' . $e->getMessage(), new DateTimeImmutable());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return ['url' => $this->geminiUrl, 'capabilities' => ['embeddings' => true]];
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $model = $request->model ?? self::MODELS[0];

        $payload = [
            'requests' => array_map(
                static fn (string $text) => [
                    'model' => 'models/' . $model,
                    'content' => ['parts' => [['text' => $text]]],
                ],
                $request->input
            ),
        ];

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post(
                $this->geminiUrl . '/models/' . $model . ':batchEmbedContents',
                [
                    'json' => $payload,
                    'query' => ['key' => $this->geminiApiKey],
                    'timeout' => $timeout,
                ]
            );
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            throw new RuntimeException('Gemini embeddings request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Gemini returned invalid JSON payload');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('Gemini API error: ' . ($data['error']['message'] ?? 'Unknown Gemini API error'));
        }

        $embeddings = array_map(
            static fn ($item) => $item['values'] ?? [],
            $data['embeddings'] ?? []
        );

        $chars = array_sum(array_map('mb_strlen', $request->input));
        $estimatedTokens = (int) ceil($chars / 4);

        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: $model,
            promptTokens: $estimatedTokens,
            totalTokens: $estimatedTokens,
            costUsd: 0.0,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return self::MODELS;
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
