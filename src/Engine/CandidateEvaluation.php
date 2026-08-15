<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Engine;

final class CandidateEvaluation
{
    /** @var CandidateRejection[] */
    public array $rejections = [];
    public ?RankScore $score = null;
    public bool $isEligible = true;

    public function __construct(
        public readonly Candidate $candidate,
    ) {}

    public function reject(CandidateRejection $rejection): void
    {
        $this->isEligible = false;
        $this->rejections[] = $rejection;
    }
}
