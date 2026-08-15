<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Adapter;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Decision\RoutingDecision;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\RoutingEngine;
use CleatSquad\LlmRouter\Exception\NoEligibleCandidateException;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use RuntimeException;

/**
 * Adapts a v5 RoutingPolicy to implement the legacy v4 RoutingStrategyInterface.
 *
 * @deprecated Use CleatSquad\LlmRouter\Engine\RoutingEngine directly.
 */
final class RoutingPolicyAdapter implements RoutingStrategyInterface
{
    private RoutingEngine $engine;
    private ?RoutingDecision $lastDecision = null;

    public function __construct(
        private readonly RoutingPolicy $policy,
    ) {
        $this->engine = new RoutingEngine($this->policy);
    }

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        try {
            $this->lastDecision = $this->engine->decide($request, $drivers);
            return $this->lastDecision->selected->driver;
        } catch (NoEligibleCandidateException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function getLastDecision(): ?RoutingDecision
    {
        return $this->lastDecision;
    }
}
