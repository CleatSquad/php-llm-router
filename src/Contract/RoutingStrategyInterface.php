<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;

interface RoutingStrategyInterface
{
    /**
     * Select the best LLM driver based on the request and a list of candidates.
     *
     * @param LLMRequest $request
     * @param LLMDriverInterface[] $drivers
     * @return LLMDriverInterface
     * @throws \RuntimeException if no driver can be selected
     */
    public function select(LLMRequest $request, array $drivers): LLMDriverInterface;
}
