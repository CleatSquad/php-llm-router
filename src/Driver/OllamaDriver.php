<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface;
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
 * LLM driver for a local Ollama instance.
 */
class OllamaDriver implements LLMDriverInterface, ModelCatalogueInterface
{
    private string $ollamaUrl;

    /**
     * Always true, and deliberately so.
     *
     * resolveModel() never refuses: it strips a provider prefix, matches
     * loosely against the models installed locally, and falls back to this
     * driver's configured default when nothing matches. There is no name it
     * would turn away, so there is none to report here.
     *
     * Answering from getModels() instead would be both wrong and expensive —
     * wrong because a loose match is not list membership, expensive because
     * that list is an HTTP call to /api/tags, and this method is contractually
     * I/O-free so a constraint can call it once per candidate per request.
     */
    public function supportsModel(string $model): bool
    {
        return true;
    }

    public function __construct(
        private HttpClient $httpClient,
        string $ollamaUrl = 'http://ollama:11434',
        private readonly string $ollamaModel = 'llama3',
        private readonly float $localLlmTimeout = 15.0
    ) {
        $this->ollamaUrl = rtrim($ollamaUrl, '/');
    }

    public function getId(): string
    {
        return 'ollama';
    }

    public function getName(): string
    {
        return 'Ollama Local';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->getClient()->get($this->ollamaUrl . '/api/tags', [
                'timeout' => 3.0,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function healthCheck(): HealthStatus
    {
        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->ollamaUrl . '/api/tags', [
                'timeout' => 3.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Ollama is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Ollama health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Ollama connection error: ' . $e->getMessage(),
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
            'url' => $this->ollamaUrl,
            'version' => '1.0.0',
            'capabilities' => [
                'chat' => true,
                'streaming' => true,
                'tools' => false,
                'vision' => false,
            ]
        ];
    }

    /**
     * Resolve a requested model id against the models actually pulled
     * locally, fuzzy-matching when there's no exact hit. Shared by chat()
     * and stream() so both pick the same model for the same request.
     */
    private function resolveModel(?string $requestedModel): string
    {
        $model = $requestedModel ?? $this->ollamaModel;

        // Normalize model name (remove provider prefix if present)
        $modelClean = str_contains($model, '/') ? explode('/', $model)[1] : $model;

        // Resilience: fallback or smart match with locally available models
        $localModels = $this->getModels();
        if (!empty($localModels)) {
            if (in_array($modelClean, $localModels, true)) {
                return $modelClean;
            }

            $matchedModel = null;

            // 0. Prioritize the default local model if it matches the request (case-insensitive)
            $defaultModel = $this->ollamaModel;
            $defaultModelClean = str_contains($defaultModel, '/') ? explode('/', $defaultModel)[1] : $defaultModel;
            if (in_array($defaultModelClean, $localModels, true) &&
                (str_contains(strtolower($defaultModelClean), strtolower($modelClean)) ||
                 str_contains(strtolower($modelClean), strtolower($defaultModelClean)))) {
                $matchedModel = $defaultModelClean;
            }

            // 1. Try to find a local model that contains the requested clean model name (excluding embedding models), case-insensitive
            if ($matchedModel === null) {
                foreach ($localModels as $localModel) {
                    if (self::isEmbeddingModelName($localModel)) {
                        continue;
                    }
                    if (str_contains(strtolower($localModel), strtolower($modelClean))) {
                        $matchedModel = $localModel;
                        break;
                    }
                }
            }

            // 2. If not found, try to find any llama or lama model if the requested model contains "llama" or "lama"
            if ($matchedModel === null && (str_contains(strtolower($modelClean), 'llama') || str_contains(strtolower($modelClean), 'lama'))) {
                foreach ($localModels as $localModel) {
                    $localLower = strtolower($localModel);
                    if (str_contains($localLower, 'llama') || str_contains($localLower, 'lama')) {
                        $matchedModel = $localModel;
                        break;
                    }
                }
            }

            // 3. If still not found, fallback to the first non-embedding model
            if ($matchedModel === null) {
                foreach ($localModels as $localModel) {
                    if (!self::isEmbeddingModelName($localModel)) {
                        $matchedModel = $localModel;
                        break;
                    }
                }
            }

            if ($matchedModel !== null) {
                return $matchedModel;
            }
        }

        return $model;
    }

    /** Fallback matching must never pick an embedding-only model as a chat substitute — it would fail immediately. */
    private static function isEmbeddingModelName(string $modelName): bool
    {
        foreach (['embed', 'nomic', 'e5-large', 'multilingual'] as $marker) {
            if (str_contains($modelName, $marker)) {
                return true;
            }
        }

        return false;
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
            $payload['options']['temperature'] = $request->temperature;
        }

        if ($request->maxTokens !== null) {
            $payload['options']['num_predict'] = $request->maxTokens;
        }

        // Ollama's `think` takes these very level names, so the neutral effort
        // maps across unchanged. Whether the model honours it depends on the
        // model: a non-thinking one simply ignores the flag.
        if ($request->reasoningEffort !== null) {
            $payload['think'] = $request->reasoningEffort === ReasoningEffort::None
                ? false
                : $request->reasoningEffort->clampTo([
                    ReasoningEffort::Low,
                    ReasoningEffort::Medium,
                    ReasoningEffort::High,
                    ReasoningEffort::Max,
                ])->value;
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->ollamaUrl . '/api/chat', [
                'json' => $payload,
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('Ollama request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Ollama returned invalid JSON payload: ' . $contents);
        }

        $content = $data['message']['content'] ?? '';
        $reasoning = $data['message']['thinking'] ?? null;
        $finishReason = $data['done_reason'] ?? 'stop';

        $promptTokens = (int) ($data['prompt_eval_count'] ?? 0);
        $completionTokens = (int) ($data['eval_count'] ?? 0);
        $totalTokens = $promptTokens + $completionTokens;

        // Custom latency from Ollama (in nanoseconds) if available
        if (isset($data['total_duration'])) {
            $latencyMs = (int) ($data['total_duration'] / 1000000);
        }

        return new LLMResponse(
            content: $content,
            model: $payload['model'],
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: 0.0, // Local execution is free
            latencyMs: $latencyMs,
            toolCalls: null,
            finishReason: $finishReason,
            reasoning: is_string($reasoning) && $reasoning !== '' ? $reasoning : null,
        );
    }

    /**
     * Always returns null — Ollama's function-calling support is too inconsistent to parse tool_calls reliably, matching chat()'s behavior.
     *
     * @return Generator<int, string, mixed, null>
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
            $payload['options']['temperature'] = $request->temperature;
        }

        if ($request->maxTokens !== null) {
            $payload['options']['num_predict'] = $request->maxTokens;
        }

        // Ollama's `think` takes these very level names, so the neutral effort
        // maps across unchanged. Whether the model honours it depends on the
        // model: a non-thinking one simply ignores the flag.
        if ($request->reasoningEffort !== null) {
            $payload['think'] = $request->reasoningEffort === ReasoningEffort::None
                ? false
                : $request->reasoningEffort->clampTo([
                    ReasoningEffort::Low,
                    ReasoningEffort::Medium,
                    ReasoningEffort::High,
                    ReasoningEffort::Max,
                ])->value;
        }

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->ollamaUrl . '/api/chat', [
                'json' => $payload,
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException('Ollama stream request failed: ' . $e->getMessage(), 0, $e);
        }

        // Ollama streams newline-delimited JSON objects (not SSE), one per
        // chunk: {"message":{"content":"..."},"done":false} ... ending with
        // a final {"done":true, ...stats} line.
        $body = $response->getBody();
        $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }

                $thinking = $data['message']['thinking'] ?? '';
                if (is_string($thinking) && $thinking !== '') {
                    $request->emitReasoning($thinking);
                }

                $chunk = $data['message']['content'] ?? '';
                if ($chunk !== '') {
                    yield $chunk;
                }

                if (($data['done'] ?? false) === true) {
                    return;
                }
            }
        }
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        try {
            $response = $this->httpClient->getClient()->get($this->ollamaUrl . '/api/tags', [
                'timeout' => 2.0,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];
            if (isset($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $modelInfo) {
                    if (isset($modelInfo['name'])) {
                        $models[] = (string) $modelInfo['name'];
                    }
                }
            }
            return $models;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsTools(): bool
    {
        return false;
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
        return CostEstimate::free();
    }
}
