<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use DateTimeImmutable;
use Generator;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use RuntimeException;

/**
 * Direct DeepSeek API driver — OpenAI-compatible chat completions
 * (same wire format LiteLLM/OpenAI/Kimi/Mistral/Groq speak, hence the
 * shared trait).
 */
class DeepSeekDriver implements LLMDriverInterface
{
    private const PRICING = [
        'deepseek-chat' => ['input' => 0.00027, 'output' => 0.0011],
        'deepseek-reasoner' => ['input' => 0.00055, 'output' => 0.00219],
    ];

    use ParsesChatCompletionSse;

    private string $deepSeekUrl;
    private string $deepSeekApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $deepSeekUrl = 'https://api.deepseek.com/v1',
        string $deepSeekApiKey = '',
        private readonly float $localLlmTimeout = 30.0
    ) {
        $this->deepSeekUrl = rtrim($deepSeekUrl, '/');
        $this->deepSeekApiKey = $deepSeekApiKey;
    }

    public function getId(): string
    {
        return 'deepseek';
    }

    public function getName(): string
    {
        return 'DeepSeek Direct';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->deepSeekApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->deepSeekApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'DeepSeek API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->deepSeekUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'DeepSeek API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'DeepSeek health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'DeepSeek connection error: ' . $e->getMessage(),
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
            'url' => $this->deepSeekUrl,
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
        $model = $this->resolveModel($request->model);

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
            $response = $this->httpClient->getClient()->post($this->deepSeekUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('DeepSeek request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('DeepSeek returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown DeepSeek API error';
            throw new RuntimeException('DeepSeek API error: ' . $message);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $textParts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $textParts[] = (string)$part['text'];
                } elseif (is_string($part)) {
                    $textParts[] = $part;
                }
            }
            $content = !empty($textParts) ? implode('', $textParts) : json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        $content = (string)$content;
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';
        $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? null;

        $promptTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($data['usage']['completion_tokens'] ?? 0);
        $totalTokens = (int) ($data['usage']['total_tokens'] ?? 0);

        $pricing = self::PRICING[$model] ?? self::PRICING['deepseek-chat'];
        $costUsd = (($promptTokens * $pricing['input']) + ($completionTokens * $pricing['output'])) / 1000;

        return new LLMResponse(
            content: $content,
            model: $data['model'] ?? $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            toolCalls: $toolCalls,
            finishReason: $finishReason
        );
    }

    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'messages' => $request->messages,
            'stream' => true,
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

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->deepSeekUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException('DeepSeek stream request failed: ' . $e->getMessage(), 0, $e);
        }

        return yield from self::readChatCompletionSse($response->getBody());
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return array_keys(self::PRICING);
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
        return true;
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        $model = $this->resolveModel($request->model);
        $inputTokens = $request->estimateInputTokens();
        $pricing = self::PRICING[$model] ?? self::PRICING['deepseek-chat'];

        $estimatedOutputTokens = $request->maxTokens ?? 200;
        $estimatedTokens = $inputTokens + $estimatedOutputTokens;
        $estimatedCostUsd = (($inputTokens * $pricing['input']) + ($estimatedOutputTokens * $pricing['output'])) / 1000;

        return new CostEstimate($pricing['input'], $pricing['output'], $estimatedTokens, $estimatedCostUsd);
    }

    private function resolveModel(?string $model): string
    {
        $model = $model ?? 'deepseek-chat';

        if (str_contains($model, '/')) {
            $model = explode('/', $model)[1];
        }

        return isset(self::PRICING[$model]) ? $model : 'deepseek-chat';
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->deepSeekApiKey,
        ];
    }
}
