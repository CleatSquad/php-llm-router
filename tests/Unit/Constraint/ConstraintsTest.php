<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Constraint;

use CleatSquad\LlmRouter\Constraint\CapabilityConstraint;
use CleatSquad\LlmRouter\Constraint\ContextWindowConstraint;
use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use PHPUnit\Framework\TestCase;

final class ConstraintsTest extends TestCase
{
    public function testCapabilityConstraintRejectsIncapableDriver(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('supportsTools')->willReturn(false);

        $candidate = new Candidate('c1', 'C1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], tools: [['name' => 'test']]);

        $constraint = new CapabilityConstraint(requireTools: true);
        $passed = $constraint->evaluate($eval, $request);

        $this->assertFalse($passed);
        $this->assertFalse($eval->isEligible);
        $this->assertCount(1, $eval->rejections);
        $this->assertSame('CapabilityConstraint', $eval->rejections[0]->constraintName);
    }

    public function testContextWindowConstraintRejectsExceededTokens(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('c1');

        $candidate = new Candidate('c1', 'C1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => str_repeat('a', 4000)]]); // ~1000 tokens

        $constraint = new ContextWindowConstraint(maxContextTokens: ['c1' => 500]);
        $passed = $constraint->evaluate($eval, $request);

        $this->assertFalse($passed);
        $this->assertFalse($eval->isEligible);
    }

    public function testQuotaConstraintRejectsExceededQuota(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('c1');

        $candidate = new Candidate('c1', 'C1', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryQuotaTracker();
        $tracker->setQuota('c1', 100);
        $tracker->recordUsage('c1', 100); // exhausted

        $constraint = new \CleatSquad\LlmRouter\Constraint\QuotaConstraint($tracker);
        $passed = $constraint->evaluate($eval, $request);

        $this->assertFalse($passed);
        $this->assertFalse($eval->isEligible);
        $this->assertCount(1, $eval->rejections);
        $this->assertSame('QuotaConstraint', $eval->rejections[0]->constraintName);
    }
}
