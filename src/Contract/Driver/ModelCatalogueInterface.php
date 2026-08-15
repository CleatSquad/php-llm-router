<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

/**
 * A driver that can answer, without calling anyone, whether it would accept a
 * given model name.
 *
 * getModels() nearly answers this, but a list can only be compared by exact
 * membership, and real drivers do not resolve that way: a priced driver strips
 * a provider prefix, Ollama matches loosely against what is installed. A
 * checker that disagrees with the thing it checks is worse than none, so the
 * question is asked of the driver itself.
 *
 * Optional: LLMDriverInterface is public API and cannot gain a method. A
 * driver not implementing this one is assumed able to serve what it is given.
 */
interface ModelCatalogueInterface
{
    /**
     * Whether this driver would accept $model, by the same rules it applies
     * when actually serving a request. Must not perform I/O.
     */
    public function supportsModel(string $model): bool;
}
