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
use CleatSquad\LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * Direct OpenAI Chat Completions API driver.
 */
class OpenAiDriver implements LLMDriverInterface, ModelCatalogueInterface
{
    use ResolvesPricedModel;

    /** Used when a request names no model at all — a caller declining to choose. */
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    private const PRICING = [
        // USD per 1k tokens = OpenAI's published per-million rate / 1000.
        // The GPT-5 family and the o-series accept `reasoning_effort`; the
        // gpt-4o pair rejects it, hence the explicit flag on those two.
        'gpt-5.6-sol' => ['input' => 0.005, 'output' => 0.030],
        'gpt-5.6-terra' => ['input' => 0.002, 'output' => 0.012],
        'gpt-5.6-luna' => ['input' => 0.0002, 'output' => 0.0012],
        'gpt-5.5' => ['input' => 0.005, 'output' => 0.030],
        'gpt-5.4' => ['input' => 0.0025, 'output' => 0.015],
        // developers.openai.com/api/docs/pricing (2026-08-25)
        'gpt-5.2-pro' => ['input' => 0.021, 'output' => 0.168],
        'gpt-5.2' => ['input' => 0.00175, 'output' => 0.014],
        'gpt-5.1' => ['input' => 0.00125, 'output' => 0.010],
        'gpt-5' => ['input' => 0.00125, 'output' => 0.010],
        'gpt-5-mini' => ['input' => 0.00025, 'output' => 0.002],
        'gpt-5-nano' => ['input' => 0.00005, 'output' => 0.0004],
        // o3-pro retired (console-reported model-drift, 2026-08-25): absent
        // from /v1/models. See the note on claude-mythos-5 in ClaudeDriver
        // for why a retired entry must not linger here.
        'o4-mini' => ['input' => 0.0011, 'output' => 0.0044],
        'o3' => ['input' => 0.002, 'output' => 0.008],
        'o3-mini' => ['input' => 0.0011, 'output' => 0.0044],
        'o1-pro' => ['input' => 0.150, 'output' => 0.600],
        'o1' => ['input' => 0.015, 'output' => 0.060],
        // Neither accepts `reasoning_effort` — sending it is a 400.
        'gpt-4o' => ['input' => 0.0025, 'output' => 0.010, 'reasoning' => false],
        'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006, 'reasoning' => false],
        // gpt-4.1 family: same generation as gpt-4o, same reasoning_effort
        // rejection (developers.openai.com/api/docs/pricing, 2026-08-25).
        'gpt-4.1' => ['input' => 0.002, 'output' => 0.008, 'reasoning' => false],
        'gpt-4.1-mini' => ['input' => 0.0004, 'output' => 0.0016, 'reasoning' => false],
        'gpt-4.1-nano' => ['input' => 0.0001, 'output' => 0.0004, 'reasoning' => false],
    ];

    use ParsesChatCompletionSse;
    use ReplaysChatCompletionReasoning;

    private string $openAiUrl;
    private string $openAiApiKey;

    /**
     * @param array<string, array{input: float, output: float, reasoning?: bool, thinkingAlwaysOn?: bool, reasoningEffort?: string, reasoningFormat?: bool}> $extraModelPricing
     *   Pricing per 1k tokens for models this release predates, merged over the
     *   shipped table. Without an entry here, an unknown model is rejected
     *   rather than silently served by the default one.
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        string $openAiUrl = 'https://api.openai.com/v1',
        string $openAiApiKey = '',
        private readonly float $localLlmTimeout = 30.0,
        array $extraModelPricing = [],
    ) {
        $this->extraModelPricing = $extraModelPricing;
        $this->openAiUrl = rtrim($openAiUrl, '/');
        $this->openAiApiKey = $openAiApiKey;
    }

    public function getId(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        return 'OpenAI Direct (ChatGPT)';
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
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'OpenAI API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->openAiUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'OpenAI API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'OpenAI health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'OpenAI connection error: ' . $e->getMessage(),
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
            'url' => $this->openAiUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'chat' => true,
                'streaming' => true,
                'tools' => true,
                'vision' => true,
            ]
        ];
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $model = $this->resolveModel($request->model);

        $payload = [
            'model' => $model,
            'messages' => self::withoutReasoningKeys($request->messages),
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

        // OpenAI spends reasoning tokens but never returns the trace, so
        // $includeReasoning has nothing to act on here — only the effort does.
        if ($request->reasoningEffort !== null) {
            $this->assertModelCanReason($model, $request);
            $payload['reasoning_effort'] = $request->reasoningEffort->value;
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('OpenAI returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown OpenAI API error';
            throw new RuntimeException('OpenAI API error: ' . $message);
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
            finishReason: $finishReason
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
            'messages' => self::withoutReasoningKeys($request->messages),
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

        // OpenAI spends reasoning tokens but never returns the trace, so
        // $includeReasoning has nothing to act on here — only the effort does.
        if ($request->reasoningEffort !== null) {
            $this->assertModelCanReason($model, $request);
            $payload['reasoning_effort'] = $request->reasoningEffort->value;
        }

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->openAiUrl . '/chat/completions', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException('OpenAI stream request failed: ' . $e->getMessage(), 0, $e);
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
        return true;
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
            'Authorization' => 'Bearer ' . $this->openAiApiKey,
        ];
    }
}
