<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Engine;

final readonly class RankScore
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public float $value,
        public string $ranker,
        public array $metadata = [],
    ) {}
}
