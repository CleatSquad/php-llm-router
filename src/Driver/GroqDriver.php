<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface;
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
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

class GroqDriver implements LLMDriverInterface, ModelCatalogueInterface
{
    use ResolvesPricedModel;

    /** Used when a request names no model at all — a caller declining to choose. */
    private const DEFAULT_MODEL = 'openai/gpt-oss-20b';

    private const PRICING = [
        // USD per 1k tokens = Groq's published per-million rate / 1000.
        //
        // Groq serves two reasoning dialects and they are not interchangeable:
        // Qwen takes reasoning_effort none|default and supports
        // reasoning_format, while GPT-OSS takes low|medium|high and rejects
        // reasoning_format outright. Sending one model the other's spelling is
        // a 400, so each entry records which it speaks.
        'qwen/qwen3.6-27b' => [
            'input' => 0.0006,
            'output' => 0.003,
            'reasoningEffort' => 'binary',
            'reasoningFormat' => true,
        ],
        'openai/gpt-oss-120b' => [
            'input' => 0.00015,
            'output' => 0.0006,
            'reasoningEffort' => 'graded',
            'reasoningFormat' => false,
        ],
        'openai/gpt-oss-20b' => [
            'input' => 0.000075,
            'output' => 0.0003,
            'reasoningEffort' => 'graded',
            'reasoningFormat' => false,
        ],
        // Instruction-tuned Llama models; Groq documents no reasoning
        // parameters for them.
        'llama-3.3-70b-versatile' => ['input' => 0.00059, 'output' => 0.00079, 'reasoning' => false],
        'llama-3.1-8b-instant' => ['input' => 0.00005, 'output' => 0.00008, 'reasoning' => false],
    ];

    use Concern\HandlesHttpRateLimit;
    use ParsesChatCompletionSse;
    use ReplaysChatCompletionReasoning;

    private string $groqUrl;
    private string $groqApiKey;

    /**
     * @param array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}> $extraModelPricing
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
        $payload = $this->applyReasoning($payload, $request, $model);

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
     * @return Generator<int, string, mixed, (array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|array{tool_calls: array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>|null, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost_usd: float}|null)>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'messages' => self::withReplayedReasoning($request->messages, 'reasoning'),
            'stream' => true,
            'stream_options' => [
                'include_usage' => true,
            ],
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
        $payload = $this->applyReasoning($payload, $request, $model);

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

        $result = yield from self::readChatCompletionSse($response->getBody(), $request);
        if ($result === null) {
            return null;
        }

        $toolCalls = $result['tool_calls'] ?? null;
        $usage = $result['usage'] ?? null;

        if ($usage === null) {
            return $toolCalls;
        }

        $pricing = $this->pricingFor($model);
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($promptTokens + $completionTokens));
        $costUsd = (($promptTokens * $pricing['input']) + ($completionTokens * $pricing['output'])) / 1000;

        return [
            'tool_calls' => $toolCalls,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $costUsd,
        ];
    }

    /**
     * @return string[]
     */

    /**
     * Adds Groq's reasoning parameters in the dialect the chosen model speaks.
     *
     * Qwen takes `reasoning_effort: none|default` and can return the trace in
     * its own field via `reasoning_format`. GPT-OSS takes the graded
     * `low|medium|high` and rejects `reasoning_format` entirely. Sending
     * either model the other's spelling earns a 400, so the catalogue records
     * which dialect each entry speaks and this picks accordingly.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyReasoning(array $payload, LLMRequest $request, string $model): array
    {
        if ($request->reasoningEffort === null) {
            return $payload;
        }

        $this->assertModelCanReason($model, $request);

        $pricing = $this->pricingFor($model);

        $payload['reasoning_effort'] = ($pricing['reasoningEffort'] ?? 'binary') === 'graded'
            ? $request->reasoningEffort->clampTo([
                ReasoningEffort::Low,
                ReasoningEffort::Medium,
                ReasoningEffort::High,
            ])->value
            : ($request->reasoningEffort === ReasoningEffort::None ? 'none' : 'default');

        // Without this the trace is folded into the answer as <think> tags —
        // but only Qwen accepts the parameter at all.
        if ($request->includeReasoning && ($pricing['reasoningFormat'] ?? false) === true) {
            $payload['reasoning_format'] = 'parsed';
        }

        return $payload;
    }

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
