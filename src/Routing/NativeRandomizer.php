<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Routing\RandomizerInterface;

final class NativeRandomizer implements RandomizerInterface
{
    public function nextInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public function nextFloat(): float
    {
        return random_int(0, PHP_INT_MAX) / (PHP_INT_MAX + 1);
    }
}
