<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Policy;

use CleatSquad\LlmRouter\Contract\Constraint\ConstraintInterface;
use CleatSquad\LlmRouter\Contract\Ranker\RankerInterface;
use CleatSquad\LlmRouter\Contract\Selector\SelectorInterface;
use CleatSquad\LlmRouter\Selector\BestCandidateSelector;

final readonly class RoutingPolicy
{
    /**
     * @param ConstraintInterface[] $constraints
     * @param RankerInterface[] $rankers
     */
    public function __construct(
        public array $constraints = [],
        public array $rankers = [],
        public SelectorInterface $selector = new BestCandidateSelector(),
        public string $name = 'custom',
    ) {}
}
