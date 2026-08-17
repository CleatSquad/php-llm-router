<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Execution;

use CleatSquad\LlmRouter\Constraint\CandidateModelConstraint;
use CleatSquad\LlmRouter\Constraint\CapabilityConstraint;
use CleatSquad\LlmRouter\Decision\RoutingDecision;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RoutingEngine;
use CleatSquad\LlmRouter\Exception\AllCandidatesFailedException;
use CleatSquad\LlmRouter\Exception\NoEligibleCandidateException;
use CleatSquad\LlmRouter\Exception\RateLimitException;
use CleatSquad\LlmRouter\Exception\UnknownModelException;
use CleatSquad\LlmRouter\Execution\PlanExecutor;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use CleatSquad\LlmRouter\Ranker\PriorityRanker;
use CleatSquad\LlmRouter\Tests\Fixtures\RecordingDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The architectural invariants of the routing/execution split. These are not
 * behaviour tests for a convenience — each one pins a property that, when it
 * was absent, produced a production failure that no unit test could see,
 * because the two halves were only ever tested apart.
 */
final class PlanExecutorTest extends TestCase
{
    private function decisionOf(Candidate ...$candidates): RoutingDecision
    {
        return new RoutingDecision(
            selected: $candidates[0],
            orderedCandidates: $candidates,
            evaluations: array_map(
                static fn (Candidate $c): CandidateEvaluation => new CandidateEvaluation($c),
                $candidates
            ),
            policyName: 'test'
        );
    }

    /**
     * INVARIANT 1 — a fallback is served its own model, never the primary's.
     *
     * The regression: a Groq candidate rate-limited on llama-3.3-70b-versatile
     * handed that same name to Mistral, OpenAI, Gemini and Claude in turn. None
     * of them serves a Groq model, so each failed on validation before reaching
     * the network. The fallback chain could not succeed at the exact moment it
     * was needed.
     */
    public function testEachCandidateReceivesItsOwnModel(): void
    {
        $groq = new RecordingDriver('groq', ['llama-3.3-70b-versatile'], [
            new RateLimitException('Groq rate limit exceeded', 180),
        ]);
        $mistral = new RecordingDriver('mistral', ['mistral-medium-latest']);

        $decision = $this->decisionOf(
            new Candidate('groq', 'Groq', $groq, 'llama-3.3-70b-versatile'),
            new Candidate('mistral', 'Mistral', $mistral, 'mistral-medium-latest'),
        );

        $response = (new PlanExecutor())->execute(new LLMRequest(messages: []), $decision);

        $this->assertSame('answered by mistral', $response->content);
        $this->assertSame(['llama-3.3-70b-versatile'], $groq->receivedModels);
        $this->assertSame(['mistral-medium-latest'], $mistral->receivedModels);
    }

    /**
     * The caller's own model is not overwritten by a candidate that has none:
     * a candidate carrying null means "let the driver choose", which for a
     * request that already names a model means letting that name stand.
     */
    public function testACandidateWithoutAModelLeavesTheRequestUntouched(): void
    {
        $driver = new RecordingDriver('openai', ['gpt-5']);
        $decision = $this->decisionOf(new Candidate('openai', 'OpenAI', $driver));

        (new PlanExecutor())->execute(new LLMRequest(messages: [], model: 'gpt-5'), $decision);

        $this->assertSame(['gpt-5'], $driver->receivedModels);
    }

    /**
     * INVARIANT 2 — no candidate outside the plan is ever executed.
     *
     * The regression this pins: the old executor held its own strategy and
     * re-selected on every attempt, so a fallback could be reached that had
     * never passed the request's constraints. A candidate lacking a capability
     * the request requires would be tried anyway, and fail as an opaque
     * provider error rather than as the constraint violation it was.
     */
    public function testOnlyCandidatesFromThePlanAreExecuted(): void
    {
        $visionCapable = new RecordingDriver('gemini', ['gemini-2.5-pro'], [], vision: true);
        $noVision = new RecordingDriver('groq', ['llama-3.3-70b-versatile']);

        $engine = new RoutingEngine(new RoutingPolicy(
            constraints: [new CapabilityConstraint(requireVision: true)],
            rankers: [new PriorityRanker(['groq' => 100, 'gemini' => 10])],
            name: 'vision-required'
        ));

        // Vision is required, so the higher-priority Groq candidate must not
        // appear in the plan at all — not merely lose the first slot.
        $request = new LLMRequest(messages: [
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/a.png']]]],
        ]);

        $decision = $engine->decide($request, [
            new Candidate('groq', 'Groq', $noVision, 'llama-3.3-70b-versatile'),
            new Candidate('gemini', 'Gemini', $visionCapable, 'gemini-2.5-pro'),
        ]);

        $planned = array_map(static fn (Candidate $c): string => $c->id, $decision->getCandidates());
        $this->assertSame(['gemini'], $planned, 'A candidate rejected by a constraint must not be in the plan.');

        (new PlanExecutor())->execute($request, $decision);

        $this->assertSame([], $noVision->receivedModels, 'A candidate absent from the plan must never be called.');
        $this->assertSame(['gemini-2.5-pro'], $visionCapable->receivedModels);
    }

    /**
     * INVARIANT 3 — an unservable candidate is a plan defect, not a failover
     * signal.
     *
     * Failing over on it spends the remaining candidates hiding the cause: the
     * request ends up reporting whatever the last provider said, and the
     * mismatch that actually broke it never appears in the message.
     */
    public function testAnUnknownModelIsNotTreatedAsAFailoverSignal(): void
    {
        $groq = new RecordingDriver('groq', ['llama-3.3-70b-versatile']);
        $mistral = new RecordingDriver('mistral', ['mistral-medium-latest']);

        // A malformed candidate: this driver cannot serve this model.
        $decision = $this->decisionOf(
            new Candidate('groq', 'Groq', $groq, 'gpt-5'),
            new Candidate('mistral', 'Mistral', $mistral, 'mistral-medium-latest'),
        );

        try {
            (new PlanExecutor())->execute(new LLMRequest(messages: []), $decision);
            $this->fail('Expected the routing failure to surface.');
        } catch (UnknownModelException $e) {
            $this->assertSame('gpt-5', $e->requestedModel);
        }

        $this->assertSame([], $mistral->receivedModels, 'A plan defect must not consume the remaining candidates.');
    }

    /**
     * The same defect, caught one step earlier: with CandidateModelConstraint
     * in the policy, an unservable pairing never reaches execution at all.
     */
    public function testAnUnservableCandidateIsRejectedBeforeThePlanIsBuilt(): void
    {
        $groq = new RecordingDriver('groq', ['llama-3.3-70b-versatile']);

        $engine = new RoutingEngine(new RoutingPolicy(
            constraints: [new CandidateModelConstraint()],
            name: 'coherent-plan'
        ));

        $this->expectException(NoEligibleCandidateException::class);

        $engine->decide(new LLMRequest(messages: []), [
            new Candidate('groq', 'Groq', $groq, 'gpt-5'),
        ]);
    }

    /** An execution failure, by contrast, does move to the next candidate. */
    public function testAProviderFailureDoesMoveDownThePlan(): void
    {
        $groq = new RecordingDriver('groq', ['llama-3.3-70b-versatile'], [
            new RuntimeException('Groq 503'),
        ]);
        $mistral = new RecordingDriver('mistral', ['mistral-medium-latest']);

        $response = (new PlanExecutor())->execute(
            new LLMRequest(messages: []),
            $this->decisionOf(
                new Candidate('groq', 'Groq', $groq, 'llama-3.3-70b-versatile'),
                new Candidate('mistral', 'Mistral', $mistral, 'mistral-medium-latest'),
            )
        );

        $this->assertSame('answered by mistral', $response->content);
    }

    /**
     * The plan is a snapshot taken before the first attempt. Re-checking
     * availability at each candidate's turn is what keeps a circuit breaker
     * that opened mid-run from being ignored — a filter over the plan, not a
     * new decision.
     */
    public function testACandidateThatBecameUnavailableMidRunIsSkipped(): void
    {
        $mistral = new RecordingDriver('mistral', ['mistral-medium-latest']);
        $openai = new RecordingDriver('openai', ['gpt-5']);

        // Groq's failure trips a breaker that also fronts Mistral — the state
        // the plan was built against no longer holds by the time Mistral's
        // turn comes.
        $trippingGroq = new class ($mistral) extends RecordingDriver {
            public function __construct(private readonly RecordingDriver $sibling)
            {
                parent::__construct('groq', ['llama-3.3-70b-versatile'], []);
            }

            public function chat(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \CleatSquad\LlmRouter\DTO\LLMResponse
            {
                $this->sibling->available = false;

                throw new RuntimeException('Groq 503');
            }
        };

        $response = (new PlanExecutor())->execute(
            new LLMRequest(messages: []),
            $this->decisionOf(
                new Candidate('groq', 'Groq', $trippingGroq, 'llama-3.3-70b-versatile'),
                new Candidate('mistral', 'Mistral', $mistral, 'mistral-medium-latest'),
                new Candidate('openai', 'OpenAI', $openai, 'gpt-5'),
            )
        );

        $this->assertSame('answered by openai', $response->content);
        $this->assertSame([], $mistral->receivedModels, 'A candidate unavailable at its turn must be skipped.');
    }

    /** Exhaustion names each candidate with its model, not just its driver. */
    public function testExhaustionReportsCandidatesWithTheirModels(): void
    {
        $groq = new RecordingDriver('groq', ['llama-3.3-70b-versatile'], [
            new RateLimitException('Groq rate limit exceeded', 180),
        ]);
        $mistral = new RecordingDriver('mistral', ['mistral-medium-latest'], [
            new RuntimeException('Mistral 503'),
        ]);

        try {
            (new PlanExecutor())->execute(
                new LLMRequest(messages: []),
                $this->decisionOf(
                    new Candidate('groq', 'Groq', $groq, 'llama-3.3-70b-versatile'),
                    new Candidate('mistral', 'Mistral', $mistral, 'mistral-medium-latest'),
                )
            );
            $this->fail('Expected exhaustion.');
        } catch (AllCandidatesFailedException $e) {
            $this->assertCount(2, $e->getFailures());
            $this->assertStringContainsString('groq (llama-3.3-70b-versatile)', $e->getMessage());
            $this->assertStringContainsString('mistral (mistral-medium-latest)', $e->getMessage());
        }
    }

    /** Streaming carries the same per-candidate model resolution. */
    public function testStreamingServesEachCandidateItsOwnModel(): void
    {
        $groq = new RecordingDriver('groq', ['llama-3.3-70b-versatile'], [
            new RateLimitException('Groq rate limit exceeded', 180),
        ]);
        $mistral = new RecordingDriver('mistral', ['mistral-medium-latest']);

        $chunks = iterator_to_array((new PlanExecutor())->stream(
            new LLMRequest(messages: []),
            $this->decisionOf(
                new Candidate('groq', 'Groq', $groq, 'llama-3.3-70b-versatile'),
                new Candidate('mistral', 'Mistral', $mistral, 'mistral-medium-latest'),
            )
        ));

        $this->assertSame(['chunk from mistral'], $chunks);
        $this->assertSame(['mistral-medium-latest'], $mistral->receivedModels);
    }

    /**
     * Once a fragment has reached the caller it cannot be withdrawn, so a
     * later failure propagates rather than splicing a second provider's output
     * onto the first one's.
     */
    public function testStreamingDoesNotFailOverAfterTheFirstChunk(): void
    {
        $failsMidStream = new class extends RecordingDriver {
            public function __construct()
            {
                parent::__construct('groq', ['llama-3.3-70b-versatile'], []);
            }

            public function stream(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \Generator
            {
                yield 'partial';

                throw new RuntimeException('connection dropped');
            }
        };
        $mistral = new RecordingDriver('mistral', ['mistral-medium-latest']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('connection dropped');

        try {
            iterator_to_array((new PlanExecutor())->stream(
                new LLMRequest(messages: []),
                $this->decisionOf(
                    new Candidate('groq', 'Groq', $failsMidStream, 'llama-3.3-70b-versatile'),
                    new Candidate('mistral', 'Mistral', $mistral, 'mistral-medium-latest'),
                )
            ));
        } finally {
            $this->assertSame([], $mistral->receivedModels);
        }
    }
    /**
     * RFC-0070, I-5 / criterion 7 — a driver that authenticates by query
     * parameter hands Guzzle a URL bearing its key, and Guzzle quotes that URL
     * in the exception message journaled here. GeminiDriver no longer does it;
     * this is the net under the next driver that would.
     */
    public function testASecretCarriedByAnExceptionMessageIsRedactedBeforeBeingJournaled(): void
    {
        $logger = new RecordingLogger();
        $failing = new RecordingDriver('gemini', ['gemini-2.5-flash'], [
            new RuntimeException('Client error: `POST https://api.example.com/v1/models?key=SUPER_SECRET_KEY` resulted in a 400'),
        ]);
        $fallback = new RecordingDriver('mistral', ['mistral-medium-latest']);

        (new PlanExecutor($logger))->execute(new LLMRequest(messages: []), $this->decisionOf(
            new Candidate('gemini', 'Gemini', $failing, 'gemini-2.5-flash'),
            new Candidate('mistral', 'Mistral', $fallback, 'mistral-medium-latest'),
        ));

        $journaled = json_encode($logger->records, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('SUPER_SECRET_KEY', $journaled);
        $this->assertStringContainsString('key=***', $journaled);
    }

    /**
     * And the same on the exhausted-plan record, which repeats every failure.
     */
    public function testTheExhaustedPlanRecordIsRedactedToo(): void
    {
        $logger = new RecordingLogger();
        $failing = new RecordingDriver('gemini', ['gemini-2.5-flash'], [
            new RuntimeException('POST https://api.example.com?key=SUPER_SECRET_KEY failed'),
        ]);

        try {
            (new PlanExecutor($logger))->execute(new LLMRequest(messages: []), $this->decisionOf(
                new Candidate('gemini', 'Gemini', $failing, 'gemini-2.5-flash'),
            ));
        } catch (AllCandidatesFailedException) {
            // The plan is meant to fail here; what matters is what it wrote.
        }

        $journaled = json_encode($logger->records, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('SUPER_SECRET_KEY', $journaled);
        $this->assertStringContainsString('key=***', $journaled);
    }
}

/**
 * Keeps every record so a test can assert on what actually reached a journal.
 */
final class RecordingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
