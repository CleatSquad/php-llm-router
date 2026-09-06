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
 * Direct OpenAI Images API driver (DALL-E / gpt-image-1).
 *
 * PRICING starts empty: no rate has been verified against OpenAI's published
 * per-image pricing yet, same rule as GlmDriver — a model without
 * a registered rate is refused, never served at a guessed price.
 */
class OpenAiImageDriver implements ImageGenerationDriverInterface
{
    // gpt-image-1, confirmed live in production (2026-09-02): only model
    // this OpenAI account can actually call (`dall-e-3` answered 400 "does
    // not exist" — retired from this account/tier, not a driver bug). It
    // never returns a `url`, only b64_json — GenerateImageExecutor handles
    // that (§ its own fallback), so this driver doesn't need to fight that.
    private const DEFAULT_MODEL = 'gpt-image-1';

    /** @var array<string, float> USD per generated image, keyed by model_id. */
    private const PRICING = [];

    private string $openAiUrl;
    private string $openAiApiKey;

    /**
     * @param array<string, float> $extraModelPricing USD per image, keyed by
     *   model_id — this package ships none for image generation yet (see
     *   class docblock), so a caller must register at least one entry here
     *   (with a verified rate) before any request naming a model can succeed.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $openAiUrl = 'https://api.openai.com/v1',
        string $openAiApiKey = '',
        private readonly float $localLlmTimeout = 60.0,
        private readonly array $extraModelPricing = [],
    ) {
        $this->openAiUrl = rtrim($openAiUrl, '/');
        $this->openAiApiKey = $openAiApiKey;
    }

    public function getId(): string
    {
        return 'openai-image';
    }

    public function getName(): string
    {
        return 'OpenAI Images (DALL-E)';
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
        return ['url' => $this->openAiUrl, 'capabilities' => ['image_generation' => true]];
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
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/images/generations', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI image request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('OpenAI returned invalid JSON payload');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('OpenAI API error: ' . ($data['error']['message'] ?? 'Unknown OpenAI API error'));
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
            'Authorization' => 'Bearer ' . $this->openAiApiKey,
        ];
    }
}
