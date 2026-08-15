<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Routing;

interface RandomizerInterface
{
    /**
     * Pick a random integer between $min and $max inclusive.
     */
    public function nextInt(int $min, int $max): int;

    /**
     * Pick a random float in range [0.0, 1.0).
     */
    public function nextFloat(): float;
}
