<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Engine;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Decision\RoutingDecision;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Exception\NoEligibleCandidateException;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use InvalidArgumentException;

final readonly class RoutingEngine
{
    public function __construct(
        private RoutingPolicy $policy,
    ) {}

    /**
     * @param LLMDriverInterface[] $drivers
     * @throws NoEligibleCandidateException
     */
    public function decide(LLMRequest $request, array $drivers): RoutingDecision
    {
        if (empty($drivers)) {
            throw new InvalidArgumentException('RoutingEngine requires at least one candidate driver.');
        }

        $evaluations = [];
        foreach ($drivers as $driver) {
            $candidate = new Candidate($driver->getId(), $driver->getName(), $driver);
            $eval = new CandidateEvaluation($candidate);

            if (!$driver->isAvailable()) {
                $eval->reject(new CandidateRejection('AvailabilityCheck', 'unavailable', 'Driver reported unavailable via isAvailable()'));
            }

            $evaluations[] = $eval;
        }

        foreach ($this->policy->constraints as $constraint) {
            foreach ($evaluations as $eval) {
                if ($eval->isEligible) {
                    $constraint->evaluate($eval, $request);
                }
            }
        }

        $eligibleEvaluations = array_values(array_filter($evaluations, static fn (CandidateEvaluation $e): bool => $e->isEligible));

        if (empty($eligibleEvaluations)) {
            throw new NoEligibleCandidateException($evaluations);
        }

        foreach ($this->policy->rankers as $ranker) {
            foreach ($eligibleEvaluations as $eval) {
                $eval->score = $ranker->score($eval, $request);
            }
        }

        $orderedCandidates = $this->policy->selector->select($eligibleEvaluations, $request);

        if (empty($orderedCandidates)) {
            throw new NoEligibleCandidateException($evaluations, 'Selector produced no ordered candidates.');
        }

        return new RoutingDecision(
            selected: $orderedCandidates[0],
            orderedCandidates: $orderedCandidates,
            evaluations: $evaluations,
            policyName: $this->policy->name
        );
    }
}
