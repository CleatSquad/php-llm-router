<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Constraint;

use CleatSquad\LlmRouter\Constraint\ContextWindowConstraint;
use CleatSquad\LlmRouter\Driver\GlmDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class ContextWindowConstraintTest extends TestCase
{
    private function driver(): GlmDriver
    {
        return new GlmDriver(new HttpClient(), glmApiKey: 'test-key', extraModelPricing: [
            'glm-4.6' => ['input' => 0.0006, 'output' => 0.0022, 'context' => 8],
        ]);
    }

    public function testRejectsARequestWhoseEstimatedTokensExceedTheModelsDeclaredContext(): void
    {
        // ~40 chars => ceil(40/4) = 10 estimated tokens, above the 8-token limit above.
        $constraint = new ContextWindowConstraint(['glm-4.6' => 8]);
        $candidate = new Candidate('glm', 'GLM Candidate', $this->driver(), 'glm-4.6');
        $evaluation = new CandidateEvaluation($candidate);

        $result = $constraint->evaluate($evaluation, new LLMRequest(
            messages: [['role' => 'user', 'content' => str_repeat('a', 40)]],
        ));

        $this->assertFalse($result);
        $this->assertFalse($evaluation->isEligible);
    }

    public function testAModelWithNoDeclaredContextIsNeverLimited(): void
    {
        $constraint = new ContextWindowConstraint([]);
        $candidate = new Candidate('glm', 'GLM Candidate', $this->driver(), 'glm-4.6');
        $evaluation = new CandidateEvaluation($candidate);

        $result = $constraint->evaluate($evaluation, new LLMRequest(
            messages: [['role' => 'user', 'content' => str_repeat('a', 1_000_000)]],
        ));

        $this->assertTrue($result);
    }
}
