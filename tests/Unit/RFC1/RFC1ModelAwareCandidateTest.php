<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\RFC1;

use CleatSquad\LlmRouter\Constraint\ContextWindowConstraint;
use CleatSquad\LlmRouter\Constraint\ModelConstraint;
use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Decision\RoutingDecision;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RoutingEngine;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use CleatSquad\LlmRouter\Ranker\PriorityRanker;
use CleatSquad\LlmRouter\Selector\WeightedSelector;
use PHPUnit\Framework\TestCase;

final class RFC1ModelAwareCandidateTest extends TestCase
{
    // 1. Candidate without model (3-argument constructor, model === null)
    public function testCandidateWithoutModel(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('c1', 'Candidate 1', $driver);

        $this->assertSame('c1', $candidate->id);
        $this->assertSame('Candidate 1', $candidate->name);
        $this->assertSame($driver, $candidate->driver);
        $this->assertNull($candidate->model);
    }

    // 2. Candidate with model (4-argument constructor, model preserved)
    public function testCandidateWithModel(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('c1', 'Candidate 1', $driver, 'gpt-4o');

        $this->assertSame('gpt-4o', $candidate->model);
    }

    // 3. Existing Candidate compatibility (all existing construction paths remain valid)
    public function testCandidateCompatibility(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $c1 = new Candidate('id-1', 'Name 1', $driver);
        $c2 = new Candidate('id-2', 'Name 2', $driver, null);

        $this->assertNull($c1->model);
        $this->assertNull($c2->model);
    }

    // 4. Raw driver candidate resolution in RoutingEngine
    public function testRawDriverCandidateResolution(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('raw-driver-1');
        $driver->method('getName')->willReturn('Raw Driver 1');
        $driver->method('isAvailable')->willReturn(true);

        $engine = new RoutingEngine(new RoutingPolicy(name: 'test'));
        $decision = $engine->decide(new LLMRequest(messages: []), [$driver]);

        $this->assertSame('raw-driver-1', $decision->selected->id);
        $this->assertNull($decision->selected->model);
    }

    // 5. Duplicate raw driver IDs: ollama, ollama#1, ollama#2
    public function testDuplicateRawDriverIDsLocalSuffix(): void
    {
        $d1 = $this->createMock(LLMDriverInterface::class);
        $d1->method('getId')->willReturn('ollama');
        $d1->method('getName')->willReturn('Ollama 1');
        $d1->method('isAvailable')->willReturn(true);

        $d2 = $this->createMock(LLMDriverInterface::class);
        $d2->method('getId')->willReturn('ollama');
        $d2->method('getName')->willReturn('Ollama 2');
        $d2->method('isAvailable')->willReturn(true);

        $d3 = $this->createMock(LLMDriverInterface::class);
        $d3->method('getId')->willReturn('ollama');
        $d3->method('getName')->willReturn('Ollama 3');
        $d3->method('isAvailable')->willReturn(true);

        $engine = new RoutingEngine(new RoutingPolicy(name: 'test'));
        $decision = $engine->decide(new LLMRequest(messages: []), [$d1, $d2, $d3]);

        $ids = array_map(static fn (Candidate $c): string => $c->id, $decision->orderedCandidates);
        $this->assertSame(['ollama', 'ollama#1', 'ollama#2'], $ids);
    }

    // 6. Explicit Candidate IDs remain untouched
    public function testExplicitCandidateIDsRemainUntouched(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('isAvailable')->willReturn(true);

        $c1 = new Candidate('ollama-node-1', 'Node 1', $driver, 'llama3');
        $c2 = new Candidate('ollama-node-2', 'Node 2', $driver, 'llama3');

        $engine = new RoutingEngine(new RoutingPolicy(name: 'test'));
        $decision = $engine->decide(new LLMRequest(messages: []), [$c1, $c2]);

        $ids = array_map(static fn (Candidate $c): string => $c->id, $decision->orderedCandidates);
        $this->assertSame(['ollama-node-1', 'ollama-node-2'], $ids);
    }

    // 7. Explicit Candidate model preserved in RoutingEngine
    public function testExplicitCandidateModelPreserved(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('isAvailable')->willReturn(true);

        $c1 = new Candidate('c1', 'C1', $driver, 'gpt-4o');
        $engine = new RoutingEngine(new RoutingPolicy(name: 'test'));
        $decision = $engine->decide(new LLMRequest(messages: []), [$c1]);

        $this->assertSame('gpt-4o', $decision->selected->model);
    }

    // 8. ModelConstraint: request model null => eligible
    public function testModelConstraintNullRequestModel(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('c1', 'C1', $driver, 'gpt-4o');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], model: null);

        $constraint = new ModelConstraint();
        $this->assertTrue($constraint->evaluate($eval, $request));
        $this->assertTrue($eval->isEligible);
    }

    // 9. ModelConstraint: explicit candidate model matches => eligible
    public function testModelConstraintExplicitModelMatches(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('c1', 'C1', $driver, 'gpt-4o');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], model: 'gpt-4o');

        $constraint = new ModelConstraint();
        $this->assertTrue($constraint->evaluate($eval, $request));
        $this->assertTrue($eval->isEligible);
    }

    // 10. ModelConstraint: explicit candidate model mismatches => rejected
    public function testModelConstraintExplicitModelMismatches(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        // Even if driver supported gpt-4.1, explicit candidate model gpt-4o takes strict precedence!
        $driver->method('getModels')->willReturn(['gpt-4o', 'gpt-4.1']);

        $candidate = new Candidate('c1', 'C1', $driver, 'gpt-4o');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], model: 'gpt-4.1');

        $constraint = new ModelConstraint();
        $this->assertFalse($constraint->evaluate($eval, $request));
        $this->assertFalse($eval->isEligible);
        $this->assertCount(1, $eval->rejections);
        $this->assertSame('ModelConstraint', $eval->rejections[0]->constraintName);
        $this->assertSame('model_mismatch', $eval->rejections[0]->reasonCode);
    }

    // 11. ModelConstraint: candidate model null + driver supports requested model => eligible
    public function testModelConstraintNullCandidateModelSupportedByDriver(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getModels')->willReturn(['gpt-4o', 'gpt-4.1']);

        $candidate = new Candidate('c1', 'C1', $driver, null);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], model: 'gpt-4.1');

        $constraint = new ModelConstraint();
        $this->assertTrue($constraint->evaluate($eval, $request));
        $this->assertTrue($eval->isEligible);
    }

    // 12. ModelConstraint: candidate model null + driver does not support requested model => rejected
    public function testModelConstraintNullCandidateModelNotSupportedByDriver(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getModels')->willReturn(['gpt-4o']);

        $candidate = new Candidate('c1', 'C1', $driver, null);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], model: 'claude-3-5-sonnet');

        $constraint = new ModelConstraint();
        $this->assertFalse($constraint->evaluate($eval, $request));
        $this->assertFalse($eval->isEligible);
        $this->assertSame('ModelConstraint', $eval->rejections[0]->constraintName);
        $this->assertSame('model_mismatch', $eval->rejections[0]->reasonCode);
    }

    // 13. Strict case-sensitive model matching
    public function testModelConstraintStrictCaseSensitivity(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('c1', 'C1', $driver, 'GPT-4O');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], model: 'gpt-4o');

        $constraint = new ModelConstraint();
        $this->assertFalse($constraint->evaluate($eval, $request));
        $this->assertFalse($eval->isEligible);
    }

    // 14. Rejection telemetry details
    public function testModelConstraintRejectionTelemetry(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getModels')->willReturn(['llama3']);

        $candidate = new Candidate('ollama-1', 'Ollama Node 1', $driver, 'llama3');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [], model: 'gpt-4o');

        $constraint = new ModelConstraint();
        $constraint->evaluate($eval, $request);

        $rejection = $eval->rejections[0];
        $this->assertSame('ModelConstraint', $rejection->constraintName);
        $this->assertSame('model_mismatch', $rejection->reasonCode);
        $this->assertSame('gpt-4o', $rejection->context['requested_model']);
        $this->assertSame('llama3', $rejection->context['candidate_model']);
        $this->assertSame(['llama3'], $rejection->context['supported_models']);
    }

    // 15. ContextWindowConstraint: candidate ID takes precedence
    public function testContextWindowConstraintCandidateIdPrecedence(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('ollama-driver');

        $candidate = new Candidate('ollama-node-1', 'Ollama Node 1', $driver, 'llama3');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => str_repeat('a', 400)]]); // ~100 tokens

        $constraint = new ContextWindowConstraint([
            'ollama-node-1' => 50,  // Candidate ID rule: 50 limit -> reject
            'llama3' => 1000,        // Model rule: 1000 limit
            'ollama-driver' => 2000, // Driver rule: 2000 limit
        ]);

        $this->assertFalse($constraint->evaluate($eval, $request));
    }

    // 16. ContextWindowConstraint: model fallback works
    public function testContextWindowConstraintModelFallback(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('ollama-driver');

        $candidate = new Candidate('ollama-node-1', 'Ollama Node 1', $driver, 'llama3');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => str_repeat('a', 400)]]); // ~100 tokens

        $constraint = new ContextWindowConstraint([
            'llama3' => 50,          // Model rule: 50 limit -> reject
            'ollama-driver' => 2000, // Driver rule: 2000 limit
        ]);

        $this->assertFalse($constraint->evaluate($eval, $request));
    }

    // 17. ContextWindowConstraint: driver ID fallback works
    public function testContextWindowConstraintDriverIdFallback(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('ollama-driver');

        $candidate = new Candidate('ollama-node-1', 'Ollama Node 1', $driver, null);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => str_repeat('a', 400)]]); // ~100 tokens

        $constraint = new ContextWindowConstraint([
            'ollama-driver' => 50, // Driver rule: 50 limit -> reject
        ]);

        $this->assertFalse($constraint->evaluate($eval, $request));
    }

    // 18. PriorityRanker: candidate ID takes precedence
    public function testPriorityRankerCandidateIdPrecedence(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('d1');

        $candidate = new Candidate('c1', 'C1', $driver, 'gpt-4o');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new PriorityRanker([
            'c1' => 100,
            'gpt-4o' => 50,
            'd1' => 10,
        ]);

        $score = $ranker->score($eval, $request);
        $this->assertEquals(100.0, $score->value);
    }

    // 19. PriorityRanker: model fallback works
    public function testPriorityRankerModelFallback(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('d1');

        $candidate = new Candidate('c1', 'C1', $driver, 'gpt-4o');
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new PriorityRanker([
            'gpt-4o' => 50,
            'd1' => 10,
        ]);

        $score = $ranker->score($eval, $request);
        $this->assertEquals(50.0, $score->value);
    }

    // 20. PriorityRanker: driver ID fallback works
    public function testPriorityRankerDriverIdFallback(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('d1');

        $candidate = new Candidate('c1', 'C1', $driver, null);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new PriorityRanker([
            'd1' => 10,
        ]);

        $score = $ranker->score($eval, $request);
        $this->assertEquals(10.0, $score->value);
    }

    // 21. WeightedSelector: ONLY candidate ID is used
    public function testWeightedSelectorOnlyCandidateIdUsed(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);

        $c1 = new Candidate('node-1', 'N1', $driver, 'llama3');
        $c2 = new Candidate('node-2', 'N2', $driver, 'llama3');

        $eval1 = new CandidateEvaluation($c1);
        $eval2 = new CandidateEvaluation($c2);

        // We configure weights only for node-1 (99.0) and model llama3 (1.0).
        // Since WeightedSelector MUST NOT fallback to model name, node-2 will get default score/weight (1.0).
        $selector = new WeightedSelector([
            'node-1' => 100.0,
            'llama3' => 50.0, // Should NOT be picked up by node-2!
        ]);

        $request = new LLMRequest(messages: []);
        $result = $selector->select([$eval1, $eval2], $request);

        $this->assertNotEmpty($result);
    }

    // 22. WeightedSelector: same model with different candidate IDs does not share weight implicitly
    public function testWeightedSelectorNoSharedModelWeight(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);

        $c1 = new Candidate('node-1', 'N1', $driver, 'llama3');
        $c2 = new Candidate('node-2', 'N2', $driver, 'llama3');

        $eval1 = new CandidateEvaluation($c1);
        $eval2 = new CandidateEvaluation($c2);

        // Map has weight for 'llama3', but WeightedSelector should not look up 'llama3' for node-1 or node-2.
        $selector = new WeightedSelector([
            'node-1' => 80.0,
            'node-2' => 20.0,
            'llama3' => 500.0,
        ]);

        $request = new LLMRequest(messages: []);
        $result = $selector->select([$eval1, $eval2], $request);
        $this->assertCount(2, $result);
    }

    // 23. RoutingDecision::toArray(): candidate model is exposed
    public function testRoutingDecisionToArrayExposesCandidateModel(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $c1 = new Candidate('c1', 'C1', $driver, 'gpt-4o');
        $eval1 = new CandidateEvaluation($c1);

        $decision = new RoutingDecision(
            selected: $c1,
            orderedCandidates: [$c1],
            evaluations: [$eval1],
            policyName: 'test-policy'
        );

        $array = $decision->toArray();

        $this->assertSame('c1', $array['selected']);
        $this->assertSame(['c1'], $array['ordered_candidates']);
        $this->assertSame('test-policy', $array['policy_name']);
        $this->assertCount(1, $array['evaluations']);
        $this->assertSame('c1', $array['evaluations'][0]['candidate_id']);
        $this->assertSame('gpt-4o', $array['evaluations'][0]['candidate_model']);
    }
}
