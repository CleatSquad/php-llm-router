<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\ImageGenerationDriverInterface;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthState;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\ImageGenerationRequest;
use CleatSquad\LlmRouter\DTO\ImageGenerationResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use RuntimeException;

/**
 * Direct GLM (Zhipu AI) Images API driver (CogView), dependent on GlmDriver
 * for the GLM API key/base URL.
 *
 * PRICING starts empty: no rate has been verified against Zhipu's published
 * per-image pricing yet, same rule as GlmDriver's chat pricing —
 * a model without a registered rate is refused, never served at a guessed
 * price.
 */
class GlmImageDriver implements ImageGenerationDriverInterface
{
    private const DEFAULT_MODEL = 'cogview-4';

    /** @var array<string, float> USD per generated image, keyed by model_id. */
    private const PRICING = [];

    private string $glmUrl;
    private string $glmApiKey;

    /**
     * @param array<string, float> $extraModelPricing USD per image, keyed by
     *   model_id — this package ships none for image generation yet (see
     *   class docblock), so a caller must register at least one entry here
     *   (with a verified rate) before any request naming a model can succeed.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $glmUrl = 'https://open.bigmodel.cn/api/paas/v4',
        string $glmApiKey = '',
        private readonly float $localLlmTimeout = 60.0,
        private readonly array $extraModelPricing = [],
    ) {
        $this->glmUrl = rtrim($glmUrl, '/');
        $this->glmApiKey = $glmApiKey;
    }

    public function getId(): string
    {
        return 'glm-image';
    }

    public function getName(): string
    {
        return 'GLM Images (CogView)';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->glmApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->glmApiKey)) {
            return new HealthStatus(HealthState::UNHEALTHY, 0, 'GLM API Key is not set', new DateTimeImmutable());
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->glmUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return $response->getStatusCode() === 200
                ? new HealthStatus(HealthState::HEALTHY, $latencyMs, 'GLM API is operational', new DateTimeImmutable())
                : new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'GLM health check returned HTTP ' . $response->getStatusCode(), new DateTimeImmutable());
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'GLM connection error: ' . $e->getMessage(), new DateTimeImmutable());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return ['url' => $this->glmUrl, 'capabilities' => ['image_generation' => true]];
    }

    public function generate(ImageGenerationRequest $request): ImageGenerationResponse
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'prompt' => $request->prompt,
            'n' => $request->count,
        ];
        if ($request->size !== null) {
            $payload['size'] = $request->size;
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->glmUrl . '/images/generations', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            throw new RuntimeException('GLM image request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('GLM returned invalid JSON payload');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('GLM API error: ' . ($data['error']['message'] ?? 'Unknown GLM API error'));
        }

        $items = $data['data'] ?? [];
        $urls = [];
        $base64Images = [];
        foreach ($items as $item) {
            if (isset($item['url'])) {
                $urls[] = (string) $item['url'];
            } elseif (isset($item['b64_json'])) {
                $base64Images[] = (string) $item['b64_json'];
            }
        }

        $pricePerImage = $this->pricingFor($model);
        $costUsd = $pricePerImage * count($items);

        return new ImageGenerationResponse(
            urls: $urls,
            base64Images: $base64Images,
            model: $model,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return array_keys($this->modelPricing());
    }

    public function estimateCost(ImageGenerationRequest $request): CostEstimate
    {
        $model = $this->resolveModel($request->model);
        $pricePerImage = $this->pricingFor($model);
        $estimatedCostUsd = $pricePerImage * $request->count;

        return new CostEstimate(
            inputCostPer1k: 0.0,
            outputCostPer1k: 0.0,
            estimatedTokens: 0,
            estimatedCostUsd: $estimatedCostUsd,
        );
    }

    /**
     * @return array<string, float>
     */
    private function modelPricing(): array
    {
        return $this->extraModelPricing + self::PRICING;
    }

    private function pricingFor(string $model): float
    {
        return $this->modelPricing()[$model] ?? 0.0;
    }

    private function resolveModel(?string $model): string
    {
        if ($model === null) {
            return self::DEFAULT_MODEL;
        }

        if (!isset($this->modelPricing()[$model])) {
            throw new \CleatSquad\LlmRouter\Exception\UnknownModelException(
                static::class,
                $model,
                array_keys($this->modelPricing()),
            );
        }

        return $model;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->glmApiKey,
        ];
    }
}
