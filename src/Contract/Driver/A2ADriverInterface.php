<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

use CleatSquad\LlmRouter\DTO\AgentCard;
use CleatSquad\LlmRouter\DTO\AgentResponse;
use Generator;

interface A2ADriverInterface extends AgentDriverInterface
{
    /**
     * Fetch (and cache) the remote agent's self-description.
     */
    public function getAgentCard(): AgentCard;

    /**
     * Yields text fragments from message/status/artifact events; Generator::getReturn() carries the final AgentResponse once exhausted.
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
