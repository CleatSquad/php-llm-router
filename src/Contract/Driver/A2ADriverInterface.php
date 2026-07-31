<?php

declare(strict_types=1);

namespace LlmRouter\Contract\Driver;

use Generator;
use LlmRouter\DTO\AgentCard;
use LlmRouter\DTO\AgentResponse;

interface A2ADriverInterface extends AgentDriverInterface
{
    /**
     * Fetch (and cache) the remote agent's self-description.
     */
    public function getAgentCard(): AgentCard;

    /**
     * Stream a task execution — yields text fragments as they arrive from
     * message/status/artifact update events. Once exhausted,
     * Generator::getReturn() carries the final AgentResponse (success,
     * full output, taskId/contextId/state in metadata).
     *
     * @param array<string, mixed> $context
     * @return Generator<int, string, mixed, AgentResponse>
     */
    public function stream(string $instruction, array $context = []): Generator;

    /**
     * Fetch the current state of a previously created task.
     */
    public function getTask(string $taskId): AgentResponse;

    /**
     * Request cancellation of a running task.
     */
    public function cancelTask(string $taskId): AgentResponse;
}
