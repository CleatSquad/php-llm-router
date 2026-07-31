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
 * Direct OpenAI Embeddings API driver.
 */
class OpenAiEmbeddingDriver implements EmbeddingDriverInterface
{
    private const PRICING = [
        'text-embedding-3-small' => 0.00002,
        'text-embedding-3-large' => 0.00013,
        'text-embedding-ada-002' => 0.0001,
    ];

    private string $openAiUrl;
    private string $openAiApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $openAiUrl = 'https://api.openai.com/v1',
        string $openAiApiKey = '',
        private readonly float $localLlmTimeout = 30.0
    ) {
        $this->openAiUrl = rtrim($openAiUrl, '/');
        $this->openAiApiKey = $openAiApiKey;
    }

    public function getId(): string
    {
        return 'openai-embedding';
    }

    public function getName(): string
    {
        return 'OpenAI Embeddings';
    }

    public function getType(): DriverType
    {
        return DriverType::EMBEDDING;
    }

    public function isAvailable(): bool
    {
        return !empty($this->openAiApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->openAiApiKey)) {
            return new HealthStatus(HealthState::UNHEALTHY, 0, 'OpenAI API Key is not set', new DateTimeImmutable());
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->openAiUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return $response->getStatusCode() === 200
                ? new HealthStatus(HealthState::HEALTHY, $latencyMs, 'OpenAI API is operational', new DateTimeImmutable())
                : new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'OpenAI health check returned HTTP ' . $response->getStatusCode(), new DateTimeImmutable());
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'OpenAI connection error: ' . $e->getMessage(), new DateTimeImmutable());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return ['url' => $this->openAiUrl, 'capabilities' => ['embeddings' => true]];
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $model = $request->model ?? array_key_first(self::PRICING);

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/embeddings', [
                'json' => ['model' => $model, 'input' => $request->input],
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI embeddings request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('OpenAI returned invalid JSON payload');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('OpenAI API error: ' . ($data['error']['message'] ?? 'Unknown OpenAI API error'));
        }

        $sorted = $data['data'] ?? [];
        usort($sorted, static fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        $embeddings = array_map(static fn ($item) => $item['embedding'] ?? [], $sorted);

        $promptTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $totalTokens = (int) ($data['usage']['total_tokens'] ?? $promptTokens);
        $pricePer1k = self::PRICING[$model] ?? self::PRICING['text-embedding-3-small'];
        $costUsd = ($totalTokens * $pricePer1k) / 1000;

        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: $data['model'] ?? $model,
            promptTokens: $promptTokens,
            totalTokens: $totalTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return array_keys(self::PRICING);
    }

    public function estimateCost(EmbeddingRequest $request): CostEstimate
    {
        $model = $request->model ?? array_key_first(self::PRICING);
        $pricePer1k = self::PRICING[$model] ?? self::PRICING['text-embedding-3-small'];

        $chars = array_sum(array_map('mb_strlen', $request->input));
        $estimatedTokens = (int) ceil($chars / 4);

        return new CostEstimate(
            inputCostPer1k: $pricePer1k,
            outputCostPer1k: 0.0,
            estimatedTokens: $estimatedTokens,
            estimatedCostUsd: ($estimatedTokens * $pricePer1k) / 1000,
        );
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->openAiApiKey,
            'Content-Type' => 'application/json',
        ];
    }
}
