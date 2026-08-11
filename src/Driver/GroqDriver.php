<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use DateTimeImmutable;
use Generator;
use GuzzleHttp\Exception\RequestException;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\Concern\ParsesChatCompletionSse;
use LlmRouter\Driver\Concern\ReplaysChatCompletionReasoning;
use LlmRouter\Driver\Concern\ResolvesPricedModel;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Enum\ReasoningEffort;
use LlmRouter\Http\HttpClient;
use RuntimeException;

class GroqDriver implements LLMDriverInterface
{
    use ResolvesPricedModel;

    /** Used when a request names no model at all — a caller declining to choose. */
    private const DEFAULT_MODEL = 'llama-3.1-8b-instant';

    private const PRICING = [
        'llama-3.3-70b-versatile' => ['input' => 0.00059, 'output' => 0.00079],
        'llama-3.1-8b-instant' => ['input' => 0.00005, 'output' => 0.00008],
        'gemma2-9b-it' => ['input' => 0.0002, 'output' => 0.0002],
    ];

    use Concern\HandlesHttpRateLimit;
    use ParsesChatCompletionSse;
    use ReplaysChatCompletionReasoning;

    private string $groqUrl;
    private string $groqApiKey;

    /**
     * @param array<string, array{input: float, output: float}> $extraModelPricing
     *   Pricing per 1k tokens for models this release predates, merged over the
     *   shipped table. Without an entry here, an unknown model is rejected
     *   rather than silently served by the default one.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $groqUrl = 'https://api.groq.com/openai/v1',
        string $groqApiKey = '',
        private readonly float $localLlmTimeout = 30.0,
        array $extraModelPricing = [],
    ) {
        $this->extraModelPricing = $extraModelPricing;
        $this->groqUrl = rtrim($groqUrl, '/');
        $this->groqApiKey = $groqApiKey;
    }

    public function getId(): string
    {
        return 'groq';
    }

    public function getName(): string
    {
        return 'Groq Direct';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->groqApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->groqApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'Groq API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->groqUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Groq API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Groq health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Groq connection error: ' . $e->getMessage(),
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
            'url' => $this->groqUrl,
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
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning'),
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

        // Groq returns the trace only when asked to parse it into its own
        // field; the default folds it into the answer as <think> tags.
        if ($request->reasoningEffort !== null) {
            $payload['reasoning_effort'] = $request->reasoningEffort === ReasoningEffort::None
                ? 'none'
                : 'default';
        }

        if ($request->includeReasoning) {
            $payload['reasoning_format'] = 'parsed';
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->groqUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (RequestException $e) {
            $this->handleRequestException($e, 'request');
        } catch (\Exception $e) {
            throw new RuntimeException('Groq request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Groq returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown Groq API error';
            throw new RuntimeException('Groq API error: ' . $message);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $reasoning = $data['choices'][0]['message']['reasoning'] ?? null;
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
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning'),
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

        // Groq returns the trace only when asked to parse it into its own
        // field; the default folds it into the answer as <think> tags.
        if ($request->reasoningEffort !== null) {
            $payload['reasoning_effort'] = $request->reasoningEffort === ReasoningEffort::None
                ? 'none'
                : 'default';
        }

        if ($request->includeReasoning) {
            $payload['reasoning_format'] = 'parsed';
        }

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->groqUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (RequestException $e) {
            $this->handleRequestException($e, 'stream request');
        } catch (\Exception $e) {
            throw new RuntimeException('Groq stream request failed: ' . $e->getMessage(), 0, $e);
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
            'Authorization' => 'Bearer ' . $this->groqApiKey,
        ];
    }

    private function handleRequestException(
        RequestException $e,
        string $operation
    ): never {
        $this->handleHttpRateLimit($e, 'Groq');

        throw new RuntimeException(
            sprintf('Groq %s failed: %s', $operation, $e->getMessage()),
            0,
            $e
        );
    }
}
