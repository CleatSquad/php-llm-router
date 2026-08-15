<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Ranker;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Ranker\PriorityRanker;
use PHPUnit\Framework\TestCase;

final class RankersTest extends TestCase
{
    public function testPriorityRankerScoresCorrectly(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('claude');

        $candidate = new Candidate('claude', 'Claude', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new PriorityRanker(priorities: ['claude' => 10]);
        $score = $ranker->score($eval, $request);

        $this->assertSame(10.0, $score->value);
        $this->assertSame('PriorityRanker', $score->ranker);
    }

    public function testCompositeRankerCombinesScoresWithWeights(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('d1');

        $candidate = new Candidate('d1', 'D1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $r1 = new PriorityRanker(priorities: ['d1' => 10.0]); // score 10.0
        $r2 = new PriorityRanker(priorities: ['d1' => 20.0]); // score 20.0

        $composite = new \CleatSquad\LlmRouter\Ranker\CompositeRanker([
            ['ranker' => $r1, 'weight' => 1.0],
            ['ranker' => $r2, 'weight' => 3.0],
        ]);

        $score = $composite->score($eval, $request);

        // (10.0 * 1.0 + 20.0 * 3.0) / 4.0 = 70.0 / 4.0 = 17.5
        $this->assertEqualsWithDelta(17.5, $score->value, 0.001);
        $this->assertSame('CompositeRanker', $score->ranker);
        $this->assertArrayHasKey('breakdown', $score->metadata);
    }

    public function testCostRankerScoresInverseOfCost(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('estimateCost')->willReturn(new \CleatSquad\LlmRouter\DTO\CostEstimate(0.001, 0.003, 10, 0.004));

        $candidate = new Candidate('d1', 'D1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new \CleatSquad\LlmRouter\Ranker\CostRanker();
        $score = $ranker->score($eval, $request);

        $expected = 1.0 / (1.0 + 0.004);
        $this->assertEqualsWithDelta($expected, $score->value, 0.001);
        $this->assertSame('CostRanker', $score->ranker);
    }

    public function testLatencyRankerScoresUsingTracker(): void
    {
        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryLatencyTracker();
        $tracker->recordLatencyMs('d1', 300.0);

        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('d1', 'D1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new \CleatSquad\LlmRouter\Ranker\LatencyRanker($tracker);
        $score = $ranker->score($eval, $request);

        // 1.0 / (1.0 + 0.3) = 1 / 1.3 = ~0.7692
        $this->assertEqualsWithDelta(1.0 / 1.3, $score->value, 0.001);
        $this->assertSame('LatencyRanker', $score->ranker);
    }

    public function testReliabilityRankerScoresUsingTracker(): void
    {
        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryReliabilityTracker(minSamples: 1);
        $tracker->recordSuccess('d1');
        $tracker->recordFailure('d1');

        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('d1', 'D1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new \CleatSquad\LlmRouter\Ranker\ReliabilityRanker($tracker);
        $score = $ranker->score($eval, $request);

        $this->assertSame(0.5, $score->value);
        $this->assertSame('ReliabilityRanker', $score->ranker);
    }

    public function testLeastBusyRankerScoresUsingTracker(): void
    {
        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryActiveRequestsTracker();
        $tracker->increment('d1');
        $tracker->increment('d1');

        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('d1', 'D1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new \CleatSquad\LlmRouter\Ranker\LeastBusyRanker($tracker);
        $score = $ranker->score($eval, $request);

        $this->assertEqualsWithDelta(1.0 / 3.0, $score->value, 0.001);
        $this->assertSame('LeastBusyRanker', $score->ranker);
    }

    public function testUsageRankerScoresUsingTracker(): void
    {
        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryUsageTracker();
        $tracker->recordUsage('d1', 9);

        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('d1', 'D1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new \CleatSquad\LlmRouter\Ranker\UsageRanker($tracker);
        $score = $ranker->score($eval, $request);

        $this->assertEqualsWithDelta(0.1, $score->value, 0.001);
        $this->assertSame('UsageRanker', $score->ranker);
    }
}
