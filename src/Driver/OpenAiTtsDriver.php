<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\SpeechSynthesisDriverInterface;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthState;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\SpeechSynthesisRequest;
use CleatSquad\LlmRouter\DTO\SpeechSynthesisResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use RuntimeException;

/**
 * Direct OpenAI Audio Speech API driver (TTS) — the synthesis counterpart of
 * OpenAiAudioDriver's transcription.
 */
class OpenAiTtsDriver implements SpeechSynthesisDriverInterface
{
    use Concern\HandlesHttpRateLimit;
    // USD per 1,000 input characters — OpenAI TTS is priced by input length,
    // not audio duration.
    private const PRICING_PER_1K_CHARS = [
        'tts-1' => 0.015,
        'tts-1-hd' => 0.030,
    ];

    private const DEFAULT_VOICE = 'alloy';

    private string $openAiUrl;
    private string $openAiApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $openAiUrl = 'https://api.openai.com/v1',
        string $openAiApiKey = '',
        private readonly float $localLlmTimeout = 60.0
    ) {
        $this->openAiUrl = rtrim($openAiUrl, '/');
        $this->openAiApiKey = $openAiApiKey;
    }

    public function getId(): string
    {
        return 'openai-tts';
    }

    public function getName(): string
    {
        return 'OpenAI Audio Speech';
    }

    public function getType(): DriverType
    {
        return DriverType::AUDIO;
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
                'headers' => ['Authorization' => 'Bearer ' . $this->openAiApiKey],
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
        return ['url' => $this->openAiUrl, 'capabilities' => ['speech_synthesis' => true]];
    }

    public function synthesize(SpeechSynthesisRequest $request): SpeechSynthesisResponse
    {
        $model = $request->model ?? 'tts-1';

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/audio/speech', [
                'json' => [
                    'model' => $model,
                    'input' => $request->text,
                    'voice' => $request->voice ?? self::DEFAULT_VOICE,
                    'response_format' => $request->format,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openAiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->handleHttpRateLimit($e, 'OpenAI Audio Speech');

            $body = $e->getResponse() !== null ? (string) $e->getResponse()->getBody() : '';
            $decoded = json_decode($body, true);
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $e->getMessage()) : $e->getMessage();
            throw new RuntimeException('OpenAI speech synthesis request failed: ' . $message, 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI speech synthesis request failed: ' . $e->getMessage(), 0, $e);
        }

        $audioContent = (string) $response->getBody();
        $pricePer1kChars = self::PRICING_PER_1K_CHARS[$model] ?? self::PRICING_PER_1K_CHARS['tts-1'];
        $costUsd = (mb_strlen($request->text) / 1000) * $pricePer1kChars;

        return new SpeechSynthesisResponse(
            audioContent: $audioContent,
            format: $request->format,
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
        return array_keys(self::PRICING_PER_1K_CHARS);
    }

    public function estimateCost(SpeechSynthesisRequest $request): CostEstimate
    {
        $model = $request->model ?? 'tts-1';
        $pricePer1kChars = self::PRICING_PER_1K_CHARS[$model] ?? self::PRICING_PER_1K_CHARS['tts-1'];

        return new CostEstimate(
            inputCostPer1k: $pricePer1kChars,
            outputCostPer1k: 0.0,
            estimatedTokens: mb_strlen($request->text),
            estimatedCostUsd: (mb_strlen($request->text) / 1000) * $pricePer1kChars,
        );
    }
}
