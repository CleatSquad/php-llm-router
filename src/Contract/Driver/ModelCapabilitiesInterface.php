<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

/**
 * A driver that can answer, per model, whether it supports vision, tools, and
 * how large a context window a model accepts — same patron as
 * ModelCatalogueInterface.
 *
 * Optional: LLMDriverInterface is public API and cannot gain a method. A
 * driver not implementing this one is assumed uniform across its models —
 * CapabilityConstraint falls back to the driver-wide supportsVision()/
 * supportsTools() and ContextWindowConstraint applies no limit.
 */
interface ModelCapabilitiesInterface
{
    /**
     * Whether $model supports vision input. Null means "not declared for
     * this model" — the caller falls back to the driver-wide flag.
     */
    public function supportsVisionFor(string $model): ?bool;

    /**
     * Whether $model supports tool calling. Null means "not declared for
     * this model" — the caller falls back to the driver-wide flag.
     */
    public function supportsToolsFor(string $model): ?bool;

    /**
     * Maximum input context window in tokens for $model. Null means
     * "not declared" — the caller applies no limit for this model.
     */
    public function contextWindowFor(string $model): ?int;
}
