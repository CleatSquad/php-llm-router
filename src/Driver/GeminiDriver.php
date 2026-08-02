<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\Concern\NormalizesVisionContent;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * Direct Google Gemini API driver (generateContent / streamGenerateContent).
 * Gemini's wire format differs enough from OpenAI-compatible ones (separate systemInstruction, "model" role, whole-object function calls) that it needs its own mapping instead of the shared ParsesChatCompletionSse trait.
 */
class GeminiDriver implements LLMDriverInterface
{
    use NormalizesVisionContent;

    private const PRICING = [
        'gemini-2.5-pro' => ['input' => 0.00125, 'output' => 0.01],
        'gemini-2.5-flash' => ['input' => 0.0003, 'output' => 0.0025],
        'gemini-2.0-flash' => ['input' => 0.0001, 'output' => 0.0004],
        'gemini-1.5-flash' => ['input' => 0.000075, 'output' => 0.0003],
        'gemini-flash-lite-latest' => ['input' => 0.000075, 'output' => 0.0003],
    ];

    private string $geminiUrl;
    private string $geminiApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta',
        string $geminiApiKey = '',
        private readonly float $localLlmTimeout = 30.0
    ) {
        $this->geminiUrl = rtrim($geminiUrl, '/');
        $this->geminiApiKey = $geminiApiKey;
    }

    public function getId(): string
    {
        return 'gemini';
    }

    public function getName(): string
    {
        return 'Google Gemini Direct';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->geminiApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->geminiApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'Gemini API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->geminiUrl . '/models', [
                'query' => ['key' => $this->geminiApiKey],
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Gemini API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Gemini health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Gemini connection error: ' . $e->getMessage(),
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
            'url' => $this->geminiUrl,
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
        $payload = $this->buildPayload($request);

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        $url = sprintf('%s/models/%s:generateContent', $this->geminiUrl, $model);

        try {
            $response = $this->httpClient->getClient()->post($url, [
                'query' => ['key' => $this->geminiApiKey],
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\Exception $e) {
            throw new RuntimeException('Gemini request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Gemini returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown Gemini API error';
            throw new RuntimeException('Gemini API error: ' . $message);
        }

        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        [$textContent, $toolCalls] = $this->extractParts($parts);

        $finishReason = $data['candidates'][0]['finishReason'] ?? 'STOP';
        $promptTokens = (int) ($data['usageMetadata']['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0);
        $totalTokens = (int) ($data['usageMetadata']['totalTokenCount'] ?? ($promptTokens + $completionTokens));

        $pricing = self::PRICING[$model] ?? self::PRICING['gemini-flash-lite-latest'];
        $costUsd = (($promptTokens * $pricing['input']) + ($completionTokens * $pricing['output'])) / 1000;

        return new LLMResponse(
            content: $textContent,
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            toolCalls: $toolCalls === [] ? null : $toolCalls,
            finishReason: $finishReason
        );
    }

    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);
        $payload = $this->buildPayload($request);

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        $url = sprintf('%s/models/%s:streamGenerateContent', $this->geminiUrl, $model);

        try {
            $response = $this->httpClient->getClient()->post($url, [
                'query' => ['alt' => 'sse', 'key' => $this->geminiApiKey],
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException('Gemini stream request failed: ' . $e->getMessage(), 0, $e);
        }

        // Each SSE "data:" line is a complete, self-contained partial
        // GenerateContentResponse — unlike OpenAI/Anthropic, a
        // functionCall's args arrive as one whole JSON object in a
        // single chunk rather than fragmented across many deltas, so
        // there's nothing to accumulate: each part is either a text
        // fragment or a complete tool call, handled the same way here
        // as in chat()'s extractParts().
        $body = $response->getBody();
        $buffer = '';
        $toolCalls = [];

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = json_decode(trim(substr($line, 5)), true);
                if (!is_array($data)) {
                    continue;
                }

                $parts = $data['candidates'][0]['content']['parts'] ?? [];
                [$text, $chunkToolCalls] = $this->extractParts($parts, count($toolCalls));

                if ($text !== '') {
                    yield $text;
                }
                foreach ($chunkToolCalls as $toolCall) {
                    $toolCalls[] = $toolCall;
                }
            }
        }

        return $toolCalls === [] ? null : $toolCalls;
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
        return true;
    }

    public function supportsReasoning(): bool
    {
        return false;
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        $model = $this->resolveModel($request->model);
        $inputTokens = $request->estimateInputTokens();
        $pricing = self::PRICING[$model] ?? self::PRICING['gemini-flash-lite-latest'];

        $estimatedOutputTokens = $request->maxTokens ?? 200;
        $estimatedTokens = $inputTokens + $estimatedOutputTokens;
        $estimatedCostUsd = (($inputTokens * $pricing['input']) + ($estimatedOutputTokens * $pricing['output'])) / 1000;

        return new CostEstimate($pricing['input'], $pricing['output'], $estimatedTokens, $estimatedCostUsd);
    }

    private function resolveModel(?string $model): string
    {
        $model = $model ?? 'gemini-flash-lite-latest';

        if (str_contains($model, '/')) {
            $model = explode('/', $model)[1];
        }

        return isset(self::PRICING[$model]) ? $model : 'gemini-flash-lite-latest';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(LLMRequest $request): array
    {
        [$system, $contents] = $this->splitSystemAndConvert($request->messages);

        $payload = ['contents' => $contents];

        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $generationConfig = [];
        if ($request->temperature !== null) {
            $generationConfig['temperature'] = $request->temperature;
        }
        if ($request->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $request->maxTokens;
        }
        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        $tools = $this->convertTools($request->tools);
        if ($tools !== null) {
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    /**
     * Splits OpenAI-style messages into Gemini's system instruction + "contents" (role: user/model).
     * Bug fixed 2026-08-01: casting content to string silently dropped every image behind a multi-part vision array.
     *
     * @param array<int, array{role: string, content: mixed}> $messages
     * @return array{0: string, 1: array<int, array{role: string, parts: array<int, array<string, mixed>>}>}
     */
    private function splitSystemAndConvert(array $messages): array
    {
        $system = [];
        $contents = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $rawContent = $message['content'] ?? '';

            if ($role === 'system') {
                $system[] = is_string($rawContent) ? $rawContent : $this->extractVisionParts($rawContent)[0];
                continue;
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => $this->convertContentForGemini($rawContent),
            ];
        }

        return [implode("\n\n", $system), $contents];
    }

    /**
     * Translates a plain string or multi-part vision content into Gemini's "parts" shape ({text}, {inlineData}).
     * Always returns at least one part — Gemini's API rejects an empty parts array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function convertContentForGemini(mixed $content): array
    {
        if (is_string($content)) {
            return [['text' => $content]];
        }

        [$text, $images] = $this->extractVisionParts($content);
        $parts = [];

        if ($text !== '') {
            $parts[] = ['text' => $text];
        }

        foreach ($images as $image) {
            $parts[] = ['inlineData' => ['mimeType' => $image['mediaType'], 'data' => $image['data']]];
        }

        return $parts !== [] ? $parts : [['text' => '']];
    }

    /**
     * Converts OpenAI-shaped tools ({type, function}) into Gemini's functionDeclarations shape.
     *
     * @param array<int, array<string, mixed>>|null $tools
     * @return array<int, array<string, mixed>>|null
     */
    private function convertTools(?array $tools): ?array
    {
        if ($tools === null || $tools === []) {
            return null;
        }

        $declarations = [];
        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? $tool;
            $declarations[] = [
                'name' => $fn['name'] ?? '',
                'description' => $fn['description'] ?? '',
                'parameters' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Splits a Gemini "parts" array into text content and tool calls re-shaped to match the other drivers' format.
     *
     * @param array<int, array<string, mixed>> $parts
     * @return array{0: string, 1: array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>}
     */
    private function extractParts(array $parts, int $toolCallOffset = 0): array
    {
        $text = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            } elseif (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id' => 'call_' . ($toolCallOffset + count($toolCalls)),
                    'type' => 'function',
                    'function' => [
                        'name' => $part['functionCall']['name'] ?? '',
                        'arguments' => json_encode($part['functionCall']['args'] ?? [], JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }

        return [$text, $toolCalls];
    }
}
