<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Contract\Driver\AudioDriverInterface;
use LlmRouter\DTO\AudioTranscriptionRequest;
use LlmRouter\DTO\AudioTranscriptionResponse;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use RuntimeException;

/**
 * Direct OpenAI Audio Transcriptions API driver (Whisper).
 */
class OpenAiAudioDriver implements AudioDriverInterface
{
    // USD per minute of audio (billed per second, rounded up by the
    // provider) — not a per-token rate like every other driver's PRICING
    // table, audio transcription is priced by duration.
    private const PRICING_PER_MINUTE = [
        'whisper-1' => 0.006,
        'gpt-4o-transcribe' => 0.006,
        'gpt-4o-mini-transcribe' => 0.003,
    ];

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
        return 'openai-audio';
    }

    public function getName(): string
    {
        return 'OpenAI Audio Transcriptions';
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
        return ['url' => $this->openAiUrl, 'capabilities' => ['transcription' => true]];
    }

    public function transcribe(AudioTranscriptionRequest $request): AudioTranscriptionResponse
    {
        $model = $request->model ?? 'whisper-1';

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
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/audio/transcriptions', [
                'multipart' => $multipart,
                'headers' => ['Authorization' => 'Bearer ' . $this->openAiApiKey],
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI transcription request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('OpenAI returned invalid JSON payload');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('OpenAI API error: ' . ($data['error']['message'] ?? 'Unknown OpenAI API error'));
        }

        $duration = isset($data['duration']) ? (float) $data['duration'] : null;
        $pricePerMinute = self::PRICING_PER_MINUTE[$model] ?? self::PRICING_PER_MINUTE['whisper-1'];
        $costUsd = $duration !== null ? ($duration / 60) * $pricePerMinute : 0.0;

        // response_format=verbose_json (requested above) returns a
        // segments[] array with per-segment avg_logprob/no_speech_prob —
        // average across segments for an overall confidence signal (a
        // single garbled segment in an otherwise clear clip shouldn't
        // dominate the verdict, so this deliberately isn't a worst-case min/max).
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
        $model = $request->model ?? 'whisper-1';
        $pricePerMinute = self::PRICING_PER_MINUTE[$model] ?? self::PRICING_PER_MINUTE['whisper-1'];

        // No duration available before the call — rough estimate from byte
        // size assuming a typical ~24kbps compressed voice encoding
        // (~3000 bytes/sec), same "good enough for a pre-call ballpark"
        // spirit as LLMRequest::estimateInputTokens()'s char/4 heuristic.
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
            'Authorization' => 'Bearer ' . $this->openAiApiKey,
        ];
    }
}
