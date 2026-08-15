<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Engine;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Decision\RoutingDecision;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\CandidateRejection;
use CleatSquad\LlmRouter\Engine\RankScore;
use CleatSquad\LlmRouter\Exception\NoEligibleCandidateException;
use PHPUnit\Framework\TestCase;

final class CoreDomainTest extends TestCase
{
    public function testCandidateImmutabilityAndGetters(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('claude', 'Claude Driver', $driver);

        $this->assertSame('claude', $candidate->id);
        $this->assertSame('Claude Driver', $candidate->name);
        $this->assertSame($driver, $candidate->driver);
    }

    public function testRankScoreValueObject(): void
    {
        $score = new RankScore(0.85, 'CostRanker', ['estimated_cost' => 0.002]);

        $this->assertSame(0.85, $score->value);
        $this->assertSame('CostRanker', $score->ranker);
        $this->assertSame(['estimated_cost' => 0.002], $score->metadata);
    }

    public function testCandidateEvaluationStateAccumulation(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('gpt4', 'OpenAI Driver', $driver);
        $eval = new CandidateEvaluation($candidate);

        $this->assertTrue($eval->isEligible);
        $this->assertEmpty($eval->rejections);
        $this->assertNull($eval->score);

        $rejection = new CandidateRejection('ContextWindow', 'exceeded', 'Token count too high');
        $eval->reject($rejection);

        $this->assertFalse($eval->isEligible);
        $this->assertCount(1, $eval->rejections);
        $this->assertSame($rejection, $eval->rejections[0]);
    }

    public function testRoutingDecisionFallbacksAndArrayExport(): void
    {
        $d1 = $this->createMock(LLMDriverInterface::class);
        $d2 = $this->createMock(LLMDriverInterface::class);
        $c1 = new Candidate('c1', 'C1', $d1);
        $c2 = new Candidate('c2', 'C2', $d2);
        $e1 = new CandidateEvaluation($c1);
        $e2 = new CandidateEvaluation($c2);

        $decision = new RoutingDecision(
            selected: $c1,
            orderedCandidates: [$c1, $c2],
            evaluations: [$e1, $e2],
            policyName: 'test-policy'
        );

        $this->assertSame($c1, $decision->selected);
        $this->assertSame([$c2], $decision->getFallbacks());
        $this->assertSame('test-policy', $decision->policyName);
        $this->assertIsArray($decision->toArray());
    }

    public function testNoEligibleCandidateExceptionCarriesEvaluations(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('c1', 'C1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $eval->reject(new CandidateRejection('Quota', 'quota_exceeded', 'Limit reached'));

        $exception = new NoEligibleCandidateException([$eval]);

        $this->assertSame([$eval], $exception->getEvaluations());
        $this->assertStringContainsString('No eligible LLM candidate', $exception->getMessage());
    }
}
