<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use CleatSquad\LlmRouter\Driver\Concern\ReplaysChatCompletionReasoning;
use CleatSquad\LlmRouter\Driver\Concern\ResolvesPricedModel;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthState;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Enum\ReasoningEffort;
use CleatSquad\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * Direct Mistral AI API driver — OpenAI-compatible chat completions
 * (same wire format LiteLLM/OpenAI/Kimi speak, hence the shared trait).
 */
class MistralDriver implements LLMDriverInterface
{
    use ResolvesPricedModel;

    /** Used when a request names no model at all — a caller declining to choose. */
    private const DEFAULT_MODEL = 'mistral-small-latest';

    private const PRICING = [
        // USD per 1k tokens = Mistral's published per-million rate / 1000.
        //
        // Magistral, Devstral and mistral-code are deliberately absent: Mistral
        // publishes no per-token rate for them, and its own model overview
        // lists Magistral and Devstral among deprecated models. Magistral is
        // the family `prompt_mode: "reasoning"` was built for, so a reasoning
        // request here needs one registered through $extraModelPricing with a
        // rate you have confirmed.
        'mistral-medium-latest' => ['input' => 0.0015, 'output' => 0.0075],
        'mistral-large-latest' => ['input' => 0.0005, 'output' => 0.0015],
        'mistral-small-latest' => ['input' => 0.00015, 'output' => 0.0006],
        'codestral-latest' => ['input' => 0.0003, 'output' => 0.0009],
        'ministral-14b-latest' => ['input' => 0.0002, 'output' => 0.0002],
        'ministral-8b-latest' => ['input' => 0.00015, 'output' => 0.00015],
        'ministral-3b-latest' => ['input' => 0.0001, 'output' => 0.0001],
    ];

    use Concern\HandlesHttpRateLimit;
    use ParsesChatCompletionSse;
    use ReplaysChatCompletionReasoning;

    private string $mistralUrl;
    private string $mistralApiKey;

    /**
     * @param array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}> $extraModelPricing
     *   Pricing per 1k tokens for models this release predates, merged over the
     *   shipped table. Without an entry here, an unknown model is rejected
     *   rather than silently served by the default one.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $mistralUrl = 'https://api.mistral.ai/v1',
        string $mistralApiKey = '',
        private readonly float $localLlmTimeout = 30.0,
        array $extraModelPricing = [],
    ) {
        $this->extraModelPricing = $extraModelPricing;
        $this->mistralUrl = rtrim($mistralUrl, '/');
        $this->mistralApiKey = $mistralApiKey;
    }

    public function getId(): string
    {
        return 'mistral';
    }

    public function getName(): string
    {
        return 'Mistral AI Direct';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->mistralApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->mistralApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'Mistral API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->mistralUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Mistral API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Mistral health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Mistral connection error: ' . $e->getMessage(),
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
            'url' => $this->mistralUrl,
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
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning_content'),
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

        // Magistral reasons by virtue of its system prompt; prompt_mode is the
        // switch, and there is no effort dial to map onto.
        if ($request->wantsReasoning()) {
            $payload['prompt_mode'] = 'reasoning';
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->mistralUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->handleHttpRateLimit($e, 'Mistral');
            throw new RuntimeException('Mistral request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Mistral request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Mistral returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['message']) && !isset($data['choices'])) {
            throw new RuntimeException('Mistral API error: ' . $data['message']);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $reasoning = $data['choices'][0]['message']['reasoning_content'] ?? null;
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

        $pricing = $this->pricingFor($model);
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
            finishReason: $finishReason,
            reasoning: is_string($reasoning) && $reasoning !== '' ? $reasoning : null,
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
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning_content'),
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

        // Magistral reasons by virtue of its system prompt; prompt_mode is the
        // switch, and there is no effort dial to map onto.
        if ($request->wantsReasoning()) {
            $payload['prompt_mode'] = 'reasoning';
        }

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->mistralUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->handleHttpRateLimit($e, 'Mistral');
            throw new RuntimeException('Mistral stream request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Mistral stream request failed: ' . $e->getMessage(), 0, $e);
        }

        return yield from self::readChatCompletionSse($response->getBody(), $request);
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return array_keys($this->modelPricing());
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
        $pricing = $this->pricingFor($model);

        $estimatedOutputTokens = $request->maxTokens ?? 200;
        $estimatedTokens = $inputTokens + $estimatedOutputTokens;
        $estimatedCostUsd = (($inputTokens * $pricing['input']) + ($estimatedOutputTokens * $pricing['output'])) / 1000;

        return new CostEstimate($pricing['input'], $pricing['output'], $estimatedTokens, $estimatedCostUsd);
    }


    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->mistralApiKey,
        ];
    }
}
