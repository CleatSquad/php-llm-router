<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Fixtures;

use CleatSquad\LlmRouter\Contract\Routing\RandomizerInterface;

final class FakeRandomizer implements RandomizerInterface
{
    /** @var int[] */
    private array $intSequence;
    private int $intIndex = 0;

    /**
     * @param int[] $intSequence
     */
    public function __construct(array $intSequence = [])
    {
        $this->intSequence = $intSequence;
    }

    public function nextInt(int $min, int $max): int
    {
        if (empty($this->intSequence)) {
            return $min;
        }

        $val = $this->intSequence[$this->intIndex % count($this->intSequence)];
        $this->intIndex++;

        return min(max($val, $min), $max);
    }

    public function nextFloat(): float
    {
        return 0.5;
    }
}
