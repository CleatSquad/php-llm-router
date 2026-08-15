<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;

/**
 * @deprecated 5.2.0 Use RoutingEngine, which returns a RoutingDecision.
 *
 * The deprecation is in the return type. A driver is not what a routing
 * decision produces — a candidate is, carrying the model it was resolved for
 * and the record of the constraints it passed. Narrowing that to
 * LLMDriverInterface discards both, at the exact boundary where execution
 * needs them, and no implementation of this interface can avoid it.
 */
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
