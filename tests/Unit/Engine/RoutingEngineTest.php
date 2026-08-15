<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Engine;

use CleatSquad\LlmRouter\Constraint\CapabilityConstraint;
use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\RoutingEngine;
use CleatSquad\LlmRouter\Exception\NoEligibleCandidateException;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use CleatSquad\LlmRouter\Ranker\PriorityRanker;
use CleatSquad\LlmRouter\Selector\BestCandidateSelector;
use PHPUnit\Framework\TestCase;

final class RoutingEngineTest extends TestCase
{
    public function testDecideSelectsBestCandidateAndBuildsDecision(): void
    {
        $d1 = $this->createMock(LLMDriverInterface::class);
        $d1->method('getId')->willReturn('d1');
        $d1->method('getName')->willReturn('Driver 1');
        $d1->method('isAvailable')->willReturn(true);

        $d2 = $this->createMock(LLMDriverInterface::class);
        $d2->method('getId')->willReturn('d2');
        $d2->method('getName')->willReturn('Driver 2');
        $d2->method('isAvailable')->willReturn(true);

        $policy = new RoutingPolicy(
            constraints: [],
            rankers: [new PriorityRanker(priorities: ['d1' => 5, 'd2' => 20])],
            selector: new BestCandidateSelector(),
            name: 'test-policy'
        );

        $engine = new RoutingEngine($policy);
        $decision = $engine->decide(new LLMRequest(messages: []), [$d1, $d2]);

        $this->assertSame('d2', $decision->selected->id);
        $this->assertCount(1, $decision->getFallbacks());
        $this->assertSame('d1', $decision->getFallbacks()[0]->id);
        $this->assertSame('test-policy', $decision->policyName);
    }

    public function testDecideThrowsNoEligibleCandidateExceptionWhenAllRejected(): void
    {
        $d1 = $this->createMock(LLMDriverInterface::class);
        $d1->method('getId')->willReturn('d1');
        $d1->method('isAvailable')->willReturn(true);
        $d1->method('supportsTools')->willReturn(false);

        $policy = new RoutingPolicy(
            constraints: [new CapabilityConstraint(requireTools: true)],
            rankers: [],
            selector: new BestCandidateSelector()
        );

        $engine = new RoutingEngine($policy);

        $this->expectException(NoEligibleCandidateException::class);
        try {
            $engine->decide(new LLMRequest(messages: []), [$d1]);
        } catch (NoEligibleCandidateException $e) {
            $this->assertCount(1, $e->getEvaluations());
            $this->assertFalse($e->getEvaluations()[0]->isEligible);
            throw $e;
        }
    }

    public function testMultipleRankersComposition(): void
    {
        $d1 = $this->createMock(LLMDriverInterface::class);
        $d1->method('getId')->willReturn('d1');
        $d1->method('getName')->willReturn('Driver 1');
        $d1->method('isAvailable')->willReturn(true);

        $d2 = $this->createMock(LLMDriverInterface::class);
        $d2->method('getId')->willReturn('d2');
        $d2->method('getName')->willReturn('Driver 2');
        $d2->method('isAvailable')->willReturn(true);

        // Ranker A gives d1: 10, d2: 0
        // Ranker B gives d1: 0, d2: 20
        // Average: d1 = 5, d2 = 10 -> d2 selected
        $r1 = new PriorityRanker(priorities: ['d1' => 10, 'd2' => 0]);
        $r2 = new PriorityRanker(priorities: ['d1' => 0, 'd2' => 20]);

        $policy = new RoutingPolicy(
            rankers: [$r1, $r2],
            selector: new BestCandidateSelector()
        );

        $engine = new RoutingEngine($policy);
        $decision = $engine->decide(new LLMRequest(messages: []), [$d1, $d2]);

        $this->assertSame('d2', $decision->selected->id);
        $this->assertNotNull($decision->evaluations[0]->score);
        $this->assertSame('CompositeRanker', $decision->evaluations[0]->score->ranker);
    }

    public function testRankerFailureIsolation(): void
    {
        $d1 = $this->createMock(LLMDriverInterface::class);
        $d1->method('getId')->willReturn('d1');
        $d1->method('getName')->willReturn('Driver 1');
        $d1->method('isAvailable')->willReturn(true);
        $d1->method('estimateCost')->willThrowException(new \RuntimeException('Cannot resolve model cost'));

        $d2 = $this->createMock(LLMDriverInterface::class);
        $d2->method('getId')->willReturn('d2');
        $d2->method('getName')->willReturn('Driver 2');
        $d2->method('isAvailable')->willReturn(true);
        $d2->method('estimateCost')->willReturn(new \CleatSquad\LlmRouter\DTO\CostEstimate(0.001, 0.001, 10, 0.001));

        $policy = new RoutingPolicy(
            rankers: [new \CleatSquad\LlmRouter\Ranker\CostRanker()],
            selector: new BestCandidateSelector()
        );

        $engine = new RoutingEngine($policy);
        $decision = $engine->decide(new LLMRequest(messages: []), [$d1, $d2]);

        // d1 failed during ranking -> marked ineligible with structured rejection, d2 survives and is selected
        $this->assertSame('d2', $decision->selected->id);
        $eval1 = $decision->evaluations[0];
        $this->assertFalse($eval1->isEligible);
        $this->assertCount(1, $eval1->rejections);
        $this->assertSame('RankerException', $eval1->rejections[0]->constraintName);
        $this->assertSame('ranker_error', $eval1->rejections[0]->reasonCode);
        $this->assertSame('Cannot resolve model cost', $eval1->rejections[0]->description);
        $this->assertSame(\RuntimeException::class, $eval1->rejections[0]->context['exception_class']);
    }
}
