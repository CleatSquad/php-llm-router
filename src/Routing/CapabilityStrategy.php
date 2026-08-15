<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class CapabilityStrategy implements RoutingStrategyInterface
{
    /**
     * @param bool $requireTools Must support function/tool calling
     * @param bool $requireVision Must support multimodal vision inputs
     * @param bool $requireReasoning Must support extended thinking/reasoning
     * @param bool $requireStreaming Must support streaming output
     * @param RoutingStrategyInterface|null $fallbackStrategy Next strategy to rank eligible candidates
     */
    public function __construct(
        private readonly bool $requireTools = false,
        private readonly bool $requireVision = false,
        private readonly bool $requireReasoning = false,
        private readonly bool $requireStreaming = false,
        private readonly ?RoutingStrategyInterface $fallbackStrategy = null
    ) {}

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        if (empty($drivers)) {
            throw new RuntimeException('No LLM drivers provided to the routing strategy.');
        }

        $available = array_values(array_filter($drivers, static fn (LLMDriverInterface $d) => $d->isAvailable()));

        if (empty($available)) {
            throw new RuntimeException('All configured LLM drivers are currently unavailable.');
        }

        $wantsTools = $this->requireTools || !empty($request->tools);
        $wantsReasoning = $this->requireReasoning || $request->wantsReasoning();
        $wantsStreaming = $this->requireStreaming || $request->stream;

        $eligible = array_values(array_filter($available, function (LLMDriverInterface $driver) use ($wantsTools, $wantsReasoning, $wantsStreaming): bool {
            if ($wantsTools && !$driver->supportsTools()) {
                return false;
            }
            if ($this->requireVision && !$driver->supportsVision()) {
                return false;
            }
            if ($wantsReasoning && !$driver->supportsReasoning()) {
                return false;
            }
            if ($wantsStreaming && !$driver->supportsStreaming()) {
                return false;
            }
            return true;
        }));

        if (!empty($eligible)) {
            if ($this->fallbackStrategy !== null) {
                return $this->fallbackStrategy->select($request, $eligible);
            }
            return $eligible[0];
        }

        // If no driver satisfies capability hard constraints, fall back to ranking available drivers
        if ($this->fallbackStrategy !== null) {
            return $this->fallbackStrategy->select($request, $available);
        }

        return $available[0];
    }
}
