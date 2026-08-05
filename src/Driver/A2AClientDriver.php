<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use Exception;
use Generator;
use LlmRouter\Contract\Driver\A2ADriverInterface;
use LlmRouter\DTO\AgentCard;
use LlmRouter\DTO\AgentResponse;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * A2A (Agent2Agent) protocol client driver — talks to any remote agent
 * that publishes an Agent Card and speaks the A2A JSON-RPC 2.0 wire
 * format (https://a2a-protocol.org): message/send, message/stream,
 * tasks/get, tasks/cancel.
 */
class A2AClientDriver implements A2ADriverInterface
{
    private ?AgentCard $agentCard = null;

    /**
     * @param string $baseUrl        Agent origin, e.g. "https://agent.example.com" (no trailing slash needed)
     * @param array<string, string> $headers Extra headers (e.g. Authorization) sent with every request
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $baseUrl,
        private readonly array $headers = [],
        private readonly float $timeoutSeconds = 30.0,
        private readonly string $agentCardPath = '/.well-known/agent-card.json',
    ) {}

    public function getId(): string
    {
        return 'a2a:' . (parse_url($this->baseUrl, PHP_URL_HOST) ?: $this->baseUrl);
    }

    public function getName(): string
    {
        try {
            return $this->getAgentCard()->name;
        } catch (Throwable) {
            return $this->getId();
        }
    }

    public function getType(): DriverType
    {
        return DriverType::AGENT;
    }

    public function isAvailable(): bool
    {
        return $this->baseUrl !== '';
    }

    public function healthCheck(): HealthStatus
    {
        $start = microtime(true);
        try {
            $card = $this->getAgentCard();
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return HealthStatus::healthy(
                $latencyMs,
                sprintf('agent "%s" reachable, %d skill(s)', $card->name, count($card->skills))
            );
        } catch (Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return HealthStatus::unhealthy($e->getMessage(), $latencyMs);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        try {
            $card = $this->getAgentCard();

            return [
                'url' => $card->url,
                'version' => $card->version,
                'protocolVersion' => $card->protocolVersion,
                'capabilities' => $card->capabilities,
            ];
        } catch (Throwable) {
            return ['baseUrl' => $this->baseUrl];
        }
    }

    public function getAgentCard(): AgentCard
    {
        if ($this->agentCard !== null) {
            return $this->agentCard;
        }

        $url = rtrim($this->baseUrl, '/') . $this->agentCardPath;
        $body = $this->httpClient->get($url, [
            'headers' => $this->headers,
            'timeout' => $this->timeoutSeconds,
        ]);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('A2A agent card at ' . $url . ' returned invalid JSON payload');
        }

        return $this->agentCard = AgentCard::fromArray($data);
    }

    public function getCapabilities(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $skill): string => (string) ($skill['id'] ?? $skill['name'] ?? ''),
            $this->getAgentCard()->skills
        )));
    }

    public function supportsStreaming(): bool
    {
        return $this->getAgentCard()->supportsStreaming();
    }

    public function execute(string $instruction, array $context = []): AgentResponse
    {
        $start = microtime(true);
        $result = $this->rpcCall('message/send', ['message' => $this->buildMessage($instruction, $context)]);

        return $this->agentResponseFromRpcResult($result, (int) ((microtime(true) - $start) * 1000));
    }

    /**
     * @param array<string, mixed> $context
     * @return Generator<int, string, mixed, AgentResponse>
     */
    public function stream(string $instruction, array $context = []): Generator
    {
        $agentCard = $this->getAgentCard();
        $payload = [
            'jsonrpc' => '2.0',
            'id' => self::uuidv4(),
            'method' => 'message/stream',
            'params' => ['message' => $this->buildMessage($instruction, $context)],
        ];

        $start = microtime(true);
        try {
            $response = $this->httpClient->getClient()->post($agentCard->url, [
                'json' => $payload,
                'headers' => $this->jsonRpcHeaders(),
                'timeout' => $this->timeoutSeconds,
                'read_timeout' => $this->timeoutSeconds,
                'stream' => true,
            ]);
        } catch (Exception $e) {
            throw new RuntimeException('A2A stream request failed: ' . $e->getMessage(), 0, $e);
        }

        return yield from $this->readA2ASse($response->getBody(), $start);
    }

    public function getTask(string $taskId): AgentResponse
    {
        $start = microtime(true);
        $result = $this->rpcCall('tasks/get', ['id' => $taskId]);

        return $this->agentResponseFromRpcResult($result, (int) ((microtime(true) - $start) * 1000));
    }

    public function cancelTask(string $taskId): AgentResponse
    {
        $start = microtime(true);
        $result = $this->rpcCall('tasks/cancel', ['id' => $taskId]);

        return $this->agentResponseFromRpcResult($result, (int) ((microtime(true) - $start) * 1000));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpcCall(string $method, array $params): array
    {
        $agentCard = $this->getAgentCard();
        $payload = [
            'jsonrpc' => '2.0',
            'id' => self::uuidv4(),
            'method' => $method,
            'params' => $params,
        ];

        $body = $this->httpClient->postJson($agentCard->url, $payload, [
            'headers' => $this->jsonRpcHeaders(),
            'timeout' => $this->timeoutSeconds,
        ]);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('A2A %s returned invalid JSON payload: %s', $method, $body));
        }

        if (isset($data['error'])) {
            $message = is_array($data['error']) ? ($data['error']['message'] ?? 'unknown') : 'unknown';
            throw new RuntimeException(sprintf('A2A %s error: %s', $method, $message));
        }

        $result = $data['result'] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException(sprintf('A2A %s returned no result', $method));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildMessage(string $instruction, array $context): array
    {
        $message = [
            'role' => 'user',
            'parts' => [['kind' => 'text', 'text' => $instruction]],
            'messageId' => self::uuidv4(),
            'kind' => 'message',
        ];

        if (isset($context['taskId'])) {
            $message['taskId'] = (string) $context['taskId'];
        }

        if (isset($context['contextId'])) {
            $message['contextId'] = (string) $context['contextId'];
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $result A Task or Message object, per the A2A schema.
     */
    private function agentResponseFromRpcResult(array $result, int $durationMs): AgentResponse
    {
        if (($result['kind'] ?? null) === 'message') {
            return new AgentResponse(
                success: true,
                output: self::extractText($result['parts'] ?? []),
                durationMs: $durationMs,
                metadata: ['kind' => 'message', 'raw' => $result],
            );
        }

        $state = $result['status']['state'] ?? 'unknown';

        return new AgentResponse(
            success: $state === 'completed',
            output: self::extractTaskText($result),
            durationMs: $durationMs,
            metadata: [
                'kind' => 'task',
                'taskId' => $result['id'] ?? null,
                'contextId' => $result['contextId'] ?? null,
                'state' => $state,
                'raw' => $result,
            ],
        );
    }

    /**
     * @return Generator<int, string, mixed, AgentResponse>
     */
    private function readA2ASse(StreamInterface $body, float $start): Generator
    {
        $buffer = '';
        $taskId = null;
        $contextId = null;
        $state = 'unknown';
        $outputParts = [];

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = json_decode(trim(substr($line, 5)), true);
                if (!is_array($data) || !is_array($data['result'] ?? null)) {
                    continue;
                }

                $result = $data['result'];
                $kind = $result['kind'] ?? null;

                $parts = match ($kind) {
                    'message' => $result['parts'] ?? [],
                    'status-update' => $result['status']['message']['parts'] ?? [],
                    'artifact-update' => $result['artifact']['parts'] ?? [],
                    default => [],
                };

                $text = self::extractText($parts);
                if ($text !== '') {
                    $outputParts[] = $text;
                    yield $text;
                }

                $taskId = $result['taskId'] ?? $result['id'] ?? $taskId;
                $contextId = $result['contextId'] ?? $contextId;

                if (($kind === 'status-update' || $kind === 'task') && isset($result['status']['state'])) {
                    $state = $result['status']['state'];
                }
            }
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        return new AgentResponse(
            success: $state === 'completed' || ($state === 'unknown' && $outputParts !== []),
            output: implode('', $outputParts),
            durationMs: $durationMs,
            metadata: ['taskId' => $taskId, 'contextId' => $contextId, 'state' => $state],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     */
    private static function extractText(array $parts): string
    {
        $texts = [];
        foreach ($parts as $part) {
            if (($part['kind'] ?? null) === 'text' && isset($part['text'])) {
                $texts[] = (string) $part['text'];
            }
        }

        return implode('', $texts);
    }

    /**
     * @param array<string, mixed> $task
     */
    private static function extractTaskText(array $task): string
    {
        if (isset($task['status']['message']['parts']) && is_array($task['status']['message']['parts'])) {
            return self::extractText($task['status']['message']['parts']);
        }

        foreach (array_reverse($task['history'] ?? []) as $message) {
            if (is_array($message) && ($message['role'] ?? null) === 'agent') {
                return self::extractText($message['parts'] ?? []);
            }
        }

        $texts = [];
        foreach ($task['artifacts'] ?? [] as $artifact) {
            if (is_array($artifact)) {
                $texts[] = self::extractText($artifact['parts'] ?? []);
            }
        }

        return implode("\n", array_filter($texts));
    }

    /**
     * @return array<string, string>
     */
    private function jsonRpcHeaders(): array
    {
        return array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $this->headers);
    }

    private static function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
