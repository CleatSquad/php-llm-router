<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Engine;

final readonly class CandidateRejection
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $constraintName,
        public string $reasonCode,
        public string $description,
        public array $context = [],
    ) {}
}
