<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Decision;

use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;

final readonly class RoutingDecision
{
    /**
     * @param Candidate $selected
     * @param Candidate[] $orderedCandidates
     * @param CandidateEvaluation[] $evaluations
     */
    public function __construct(
        public Candidate $selected,
        public array $orderedCandidates,
        public array $evaluations,
        public string $policyName = 'custom',
    ) {}

    /**
     * @return Candidate[]
     */
    public function getFallbacks(): array
    {
        return array_slice($this->orderedCandidates, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'selected' => $this->selected->id,
            'ordered_candidates' => array_map(static fn (Candidate $c): string => $c->id, $this->orderedCandidates),
            'policy_name' => $this->policyName,
            'evaluations' => array_map(static function (CandidateEvaluation $e): array {
                return [
                    'candidate_id' => $e->candidate->id,
                    'candidate_model' => $e->candidate->model,
                    'is_eligible' => $e->isEligible,
                    'score' => $e->score !== null ? [
                        'value' => $e->score->value,
                        'ranker' => $e->score->ranker,
                        'metadata' => $e->score->metadata,
                    ] : null,
                    'rejections' => array_map(static fn ($r): array => [
                        'constraint' => $r->constraintName,
                        'reason' => $r->reasonCode,
                        'description' => $r->description,
                    ], $e->rejections),
                ];
            }, $this->evaluations),
        ];
    }
}
