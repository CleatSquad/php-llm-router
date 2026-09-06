<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Constraint;

use CleatSquad\LlmRouter\Constraint\CapabilityConstraint;
use CleatSquad\LlmRouter\Driver\GlmDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * A model-level capability flag must win over the driver-wide
 * method — proven on GlmDriver, whose PRICING (and therefore per-model
 * flags) is entirely caller-supplied.
 */
final class CapabilityConstraintPerModelTest extends TestCase
{
    private function driver(): GlmDriver
    {
        return new GlmDriver(new HttpClient(), glmApiKey: 'test-key', extraModelPricing: [
            // Explicitly marked vision-capable, unlike GlmDriver::supportsVision()
            // (false, driver-wide) — the model-level flag must win.
            'glm-4.6v' => ['input' => 0.001, 'output' => 0.003, 'vision' => true],
            // Explicitly marked tools-incapable, unlike the driver-wide true.
            'glm-4.6-flash' => ['input' => 0.0002, 'output' => 0.0005, 'tools' => false],
            // No flags declared: falls back to the driver-wide methods.
            'glm-4.6' => ['input' => 0.0006, 'output' => 0.0022],
        ]);
    }

    public function testAModelExplicitlyMarkedVisionCapableIsAcceptedEvenIfTheDriverIsNot(): void
    {
        $constraint = new CapabilityConstraint(requireVision: true);
        $candidate = new Candidate('glm', 'GLM Candidate', $this->driver(), 'glm-4.6v');
        $evaluation = new CandidateEvaluation($candidate);

        $result = $constraint->evaluate($evaluation, new LLMRequest(messages: []));

        $this->assertTrue($result);
        $this->assertTrue($evaluation->isEligible);
    }

    public function testADriverWideVisionFalseStillRejectsAModelWithNoExplicitFlag(): void
    {
        $constraint = new CapabilityConstraint(requireVision: true);
        $candidate = new Candidate('glm', 'GLM Candidate', $this->driver(), 'glm-4.6');
        $evaluation = new CandidateEvaluation($candidate);

        $result = $constraint->evaluate($evaluation, new LLMRequest(messages: []));

        $this->assertFalse($result);
        $this->assertFalse($evaluation->isEligible);
    }

    public function testAModelExplicitlyMarkedToolsIncapableIsRejectedEvenIfTheDriverSupportsToolsElsewhere(): void
    {
        $constraint = new CapabilityConstraint();
        $candidate = new Candidate('glm', 'GLM Candidate', $this->driver(), 'glm-4.6-flash');
        $evaluation = new CandidateEvaluation($candidate);

        $result = $constraint->evaluate($evaluation, new LLMRequest(
            messages: [],
            tools: [['type' => 'function', 'function' => ['name' => 'noop']]],
        ));

        $this->assertFalse($result);
    }

    public function testANoFlagModelFallsBackToTheDriverWideMethodNonRegression(): void
    {
        $constraint = new CapabilityConstraint();
        $candidate = new Candidate('glm', 'GLM Candidate', $this->driver(), 'glm-4.6');
        $evaluation = new CandidateEvaluation($candidate);

        // GlmDriver::supportsTools() is true driver-wide, glm-4.6 declares no
        // explicit "tools" flag — must still be accepted.
        $result = $constraint->evaluate($evaluation, new LLMRequest(
            messages: [],
            tools: [['type' => 'function', 'function' => ['name' => 'noop']]],
        ));

        $this->assertTrue($result);
    }
}
