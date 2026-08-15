<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Engine;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;

final readonly class Candidate
{
    public function __construct(
        public string $id,
        public string $name,
        public LLMDriverInterface $driver,
    ) {}
}
