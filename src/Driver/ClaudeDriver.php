<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use DateTimeImmutable;
use Generator;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\Concern\NormalizesVisionContent;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use RuntimeException;

class ClaudeDriver implements LLMDriverInterface
{
    use NormalizesVisionContent;

    private const ANTHROPIC_VERSION = '2023-06-01';

    /** @var array<string, array{input: float, output: float}> Cost per 1k tokens in USD */
    private const PRICING = [
        'claude-opus-4-8' => ['input' => 0.005, 'output' => 0.025],
        'claude-sonnet-5' => ['input' => 0.003, 'output' => 0.015],
        'claude-haiku-4-5' => ['input' => 0.001, 'output' => 0.005],
    ];

    use Concern\HandlesHttpRateLimit;

    private string $anthropicUrl;
    private string $anthropicApiKey;

    public function __construct(
        private readonly HttpClient $httpClient,
        string $anthropicUrl = 'https://api.anthropic.com/v1',
        string $anthropicApiKey = '',
        private readonly float $localLlmTimeout = 30.0
    ) {
        $this->anthropicUrl = rtrim($anthropicUrl, '/');
        $this->anthropicApiKey = $anthropicApiKey;
    }

    public function getId(): string
    {
        return 'claude';
    }

    public function getName(): string
    {
        return 'Claude Direct (Anthropic)';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return !empty($this->anthropicApiKey);
    }

    public function healthCheck(): HealthStatus
    {
        if (empty($this->anthropicApiKey)) {
            return new HealthStatus(
                HealthState::UNHEALTHY,
                0,
                'Anthropic API Key is not set',
                new DateTimeImmutable()
            );
        }

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->getClient()->get($this->anthropicUrl . '/models', [
                'headers' => $this->getHeaders(),
                'timeout' => 4.0,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->getStatusCode() === 200) {
                return new HealthStatus(
                    HealthState::HEALTHY,
                    $latencyMs,
                    'Claude API is operational',
                    new DateTimeImmutable()
                );
            }

            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Claude health check returned HTTP ' . $response->getStatusCode(),
                new DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return new HealthStatus(
                HealthState::UNHEALTHY,
                $latencyMs,
                'Claude connection error: ' . $e->getMessage(),
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
            'url' => $this->anthropicUrl,
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
        [$system, $messages] = $this->splitSystemMessages($request->messages);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? 4096,
            'stream' => $request->stream,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        $startTime = microtime(true);
        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;
        try {
            $response = $this->httpClient->getClient()->post($this->anthropicUrl . '/messages', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ]);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->handleHttpRateLimit($e, 'Claude');
            throw new RuntimeException('Claude request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Claude request failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Claude returned invalid JSON payload: ' . $contents);
        }

        if (isset($data['type']) && $data['type'] === 'error') {
            $message = $data['error']['message'] ?? 'Unknown Claude API error';
            throw new RuntimeException('Claude API error: ' . $message);
        }

        $textContent = '';
        $toolCalls = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $textContent .= $block['text'] ?? '';
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'] ?? '',
                        'arguments' => json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }

        $promptTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $completionTokens = (int) ($data['usage']['output_tokens'] ?? 0);
        $totalTokens = $promptTokens + $completionTokens;

        $pricing = self::PRICING[$model] ?? self::PRICING['claude-sonnet-5'];
        $costUsd = (($promptTokens * $pricing['input']) + ($completionTokens * $pricing['output'])) / 1000;

        return new LLMResponse(
            content: $textContent,
            model: $data['model'] ?? $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            toolCalls: !empty($toolCalls) ? $toolCalls : null,
            finishReason: $data['stop_reason'] ?? 'stop'
        );
    }

    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $model = $this->resolveModel($request->model);
        [$system, $messages] = $this->splitSystemMessages($request->messages);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? 4096,
            'stream' => true,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->tools !== null) {
            $payload['tools'] = $request->tools;
        }

        $timeout = $request->timeoutSeconds ?? $this->localLlmTimeout;

        try {
            $response = $this->httpClient->getClient()->post($this->anthropicUrl . '/messages', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'stream' => true,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->handleHttpRateLimit($e, 'Claude');
            throw new RuntimeException('Claude stream request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Claude stream request failed: ' . $e->getMessage(), 0, $e);
        }

        // Anthropic's SSE framing differs from OpenAI's: "event: <type>" + "data: {json}" lines;
        // text arrives via content_block_delta/text_delta, tool calls via content_block_start
        // (id, name) then content_block_delta/input_json_delta chunks, re-shaped here into the
        // same {id, type: "function", function: {name, arguments}} array chat() returns.
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

                $eventType = $data['type'] ?? '';
                $index = $data['index'] ?? 0;

                if ($eventType === 'content_block_start') {
                    $block = $data['content_block'] ?? [];
                    if (($block['type'] ?? '') === 'tool_use') {
                        $toolCalls[$index] = [
                            'id' => $block['id'] ?? '',
                            'type' => 'function',
                            'function' => ['name' => $block['name'] ?? '', 'arguments' => ''],
                        ];
                    }
                }

                if ($eventType === 'content_block_delta') {
                    $deltaType = $data['delta']['type'] ?? '';

                    if ($deltaType === 'text_delta') {
                        $text = $data['delta']['text'] ?? '';
                        if ($text !== '') {
                            yield $text;
                        }
                    } elseif ($deltaType === 'input_json_delta' && isset($toolCalls[$index])) {
                        $toolCalls[$index]['function']['arguments'] .= $data['delta']['partial_json'] ?? '';
                    }
                }

                if ($eventType === 'message_stop') {
                    return $toolCalls === [] ? null : array_values($toolCalls);
                }
            }
        }

        return $toolCalls === [] ? null : array_values($toolCalls);
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
        return true;
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        $model = $this->resolveModel($request->model);
        $inputTokens = $request->estimateInputTokens();
        $pricing = self::PRICING[$model] ?? self::PRICING['claude-sonnet-5'];

        $estimatedOutputTokens = $request->maxTokens ?? 200;
        $estimatedTokens = $inputTokens + $estimatedOutputTokens;
        $estimatedCostUsd = (($inputTokens * $pricing['input']) + ($estimatedOutputTokens * $pricing['output'])) / 1000;

        return new CostEstimate($pricing['input'], $pricing['output'], $estimatedTokens, $estimatedCostUsd);
    }

    private function resolveModel(?string $model): string
    {
        $model = $model ?? 'claude-sonnet-5';

        // Strip provider prefix if present
        if (str_contains($model, '/')) {
            $model = explode('/', $model)[1];
        }

        return isset(self::PRICING[$model]) ? $model : 'claude-sonnet-5';
    }

    /**
     * Splits OpenAI-style messages (may include a "system" role) into Anthropic's system prompt + message list.
     * Non-system content is translated via convertContentForClaude() — a 2026-08-01 fix, since passing OpenAI's vision shape straight through silently broke every vision request to Claude.
     *
     * @param array<int, array{role: string, content: mixed}> $messages
     * @return array{0: string, 1: array<int, array{role: string, content: mixed}>}
     */
    private function splitSystemMessages(array $messages): array
    {
        $system = [];
        $rest = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                $content = $message['content'] ?? '';
                $system[] = is_string($content) ? $content : $this->extractVisionParts($content)[0];
            } else {
                $rest[] = [
                    'role' => $message['role'],
                    'content' => $this->convertContentForClaude($message['content'] ?? ''),
                ];
            }
        }

        return [implode("\n\n", $system), $rest];
    }

    /**
     * Translates OpenAI-shaped vision content into Anthropic's content-block array; plain strings pass through unchanged.
     * Shared OpenAI-shape parsing lives in NormalizesVisionContent::extractVisionParts().
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function convertContentForClaude(mixed $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }

        [$text, $images] = $this->extractVisionParts($content);
        $blocks = [];

        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($images as $image) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mediaType'],
                    'data' => $image['data'],
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->anthropicApiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ];
    }
}
