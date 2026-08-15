<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\AudioDriverInterface;
use CleatSquad\LlmRouter\DTO\AudioTranscriptionRequest;
use CleatSquad\LlmRouter\DTO\AudioTranscriptionResponse;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthState;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use RuntimeException;

/**
 * Direct Groq Audio Transcriptions API driver — OpenAI-compatible
 * multipart/form-data request shape, Groq's own fast Whisper inference.
 */
class GroqAudioDriver implements AudioDriverInterface
{
    use Concern\HandlesHttpRateLimit;
    // USD per minute of audio, same billing shape as OpenAI's Whisper API.
    private const PRICING_PER_MINUTE = [
        'whisper-large-v3' => 0.00185,
        'whisper-large-v3-turbo' => 0.00067,
        'distil-whisper-large-v3-en' => 0.00033,
    ];

    private string $groqUrl;
    private string $groqApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $groqUrl = 'https://api.groq.com/openai/v1',
        string $groqApiKey = '',
        private readonly float $localLlmTimeout = 60.0
    ) {
        $this->groqUrl = rtrim($groqUrl, '/');
        $this->groqApiKey = $groqApiKey;
    }

    public function getId(): string
    {
        return 'groq-audio';
    }

    public function getName(): string
    {
        return 'Groq Audio Transcriptions';
    }

    public function getType(): DriverType
    {
        return DriverType::AUDIO;
    }

    public function isAvailable(): bool
    {
        return !empty($this->groqApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->groqApiKey)) {
            return new HealthStatus(HealthState::UNHEALTHY, 0, 'Groq API Key is not set', new DateTimeImmutable());
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->groqUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return $response->getStatusCode() === 200
                ? new HealthStatus(HealthState::HEALTHY, $latencyMs, 'Groq API is operational', new DateTimeImmutable())
                : new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'Groq health check returned HTTP ' . $response->getStatusCode(), new DateTimeImmutable());
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(HealthState::UNHEALTHY, $latencyMs, 'Groq connection error: ' . $e->getMessage(), new DateTimeImmutable());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return ['url' => $this->groqUrl, 'capabilities' => ['transcription' => true]];
    }

    public function transcribe(AudioTranscriptionRequest $request): AudioTranscriptionResponse
    {
        $model = $request->model ?? 'whisper-large-v3';

        $multipart = [
            ['name' => 'file', 'contents' => $request->audioContent, 'filename' => $request->filename],
            ['name' => 'model', 'contents' => $model],
            ['name' => 'response_format', 'contents' => 'verbose_json'],
        ];
        if ($request->language !== null) {
            $multipart[] = ['name' => 'language', 'contents' => $request->language];
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->groqUrl . '/audio/transcriptions', [
                'multipart' => $multipart,
                'headers' => ['Authorization' => 'Bearer ' . $this->groqApiKey],
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            if ($e instanceof \GuzzleHttp\Exception\RequestException) {
                $this->handleHttpRateLimit($e, 'Groq Audio');
            }
            throw new RuntimeException('Groq transcription request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Groq transcription request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Groq returned invalid JSON payload');
        }

        if (isset($data['error'])) {
            $message = is_array($data['error']) ? ($data['error']['message'] ?? 'unknown') : $data['error'];
            throw new RuntimeException('Groq API error: ' . $message);
        }

        $duration = isset($data['duration']) ? (float) $data['duration'] : null;
        $pricePerMinute = self::PRICING_PER_MINUTE[$model] ?? self::PRICING_PER_MINUTE['whisper-large-v3'];
        $costUsd = $duration !== null ? ($duration / 60) * $pricePerMinute : 0.0;

        // Groq's Whisper endpoint is OpenAI-compatible: response_format=
        // verbose_json (requested above) returns the same segments[] shape
        // with per-segment avg_logprob/no_speech_prob — see OpenAiAudioDriver
        // for why this is averaged across segments rather than worst-case.
        $segments = $data['segments'] ?? [];
        $avgLogprob = null;
        $noSpeechProb = null;
        if (is_array($segments) && $segments !== []) {
            $logprobs = array_column($segments, 'avg_logprob');
            $noSpeechProbs = array_column($segments, 'no_speech_prob');
            $avgLogprob = array_sum($logprobs) / count($logprobs);
            $noSpeechProb = array_sum($noSpeechProbs) / count($noSpeechProbs);
        }

        return new AudioTranscriptionResponse(
            text: (string) ($data['text'] ?? ''),
            model: $model,
            language: $data['language'] ?? $request->language,
            durationSeconds: $duration,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            avgLogprob: $avgLogprob,
            noSpeechProb: $noSpeechProb,
        );
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return array_keys(self::PRICING_PER_MINUTE);
    }

    public function estimateCost(AudioTranscriptionRequest $request): CostEstimate
    {
        $model = $request->model ?? 'whisper-large-v3';
        $pricePerMinute = self::PRICING_PER_MINUTE[$model] ?? self::PRICING_PER_MINUTE['whisper-large-v3'];

        $estimatedSeconds = strlen($request->audioContent) / 3000;

        return new CostEstimate(
            inputCostPer1k: 0.0,
            outputCostPer1k: 0.0,
            estimatedTokens: 0,
            estimatedCostUsd: ($estimatedSeconds / 60) * $pricePerMinute,
        );
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->groqApiKey,
        ];
    }
}
