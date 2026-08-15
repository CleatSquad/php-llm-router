<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Constraint;

use CleatSquad\LlmRouter\Constraint\CandidateModelConstraint;
use CleatSquad\LlmRouter\Constraint\ModelConstraint;
use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Driver\GroqDriver;
use CleatSquad\LlmRouter\Driver\OllamaDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Http\HttpClient;
use CleatSquad\LlmRouter\Tests\Fixtures\RecordingDriver;
use PHPUnit\Framework\TestCase;

final class CandidateModelConstraintTest extends TestCase
{
    private function evaluate(Candidate $candidate, ?string $requestModel = null): CandidateEvaluation
    {
        $eval = new CandidateEvaluation($candidate);
        (new CandidateModelConstraint())->evaluate($eval, new LLMRequest(messages: [], model: $requestModel));

        return $eval;
    }

    /**
     * The gap this constraint exists to close: with no model on the request,
     * ModelConstraint stands down — correctly, since a caller who asked for
     * nothing cannot be overruled — and nothing else was checking that the
     * candidate itself made sense.
     */
    public function testItCatchesWhatModelConstraintDeliberatelyIgnores(): void
    {
        $driver = new RecordingDriver('groq', ['llama-3.3-70b-versatile']);
        $incoherent = new Candidate('groq', 'Groq', $driver, 'gpt-5');
        $request = new LLMRequest(messages: [], model: null);

        $modelConstraintEval = new CandidateEvaluation($incoherent);
        $this->assertTrue(
            (new ModelConstraint())->evaluate($modelConstraintEval, $request),
            'ModelConstraint has no opinion when the caller named no model.'
        );

        $this->assertFalse($this->evaluate($incoherent)->isEligible);
    }

    public function testACandidateWhoseDriverServesItsModelIsEligible(): void
    {
        $driver = new RecordingDriver('groq', ['llama-3.3-70b-versatile']);
        $candidate = new Candidate('groq', 'Groq', $driver, 'llama-3.3-70b-versatile');

        $this->assertTrue($this->evaluate($candidate)->isEligible);
    }

    public function testACandidateWithoutAModelIsAlwaysEligible(): void
    {
        $driver = new RecordingDriver('groq', ['llama-3.3-70b-versatile']);

        $this->assertTrue($this->evaluate(new Candidate('groq', 'Groq', $driver))->isEligible);
    }

    /**
     * A driver that publishes no catalogue keeps the benefit of the doubt —
     * the assumption in force before ModelCatalogueInterface existed, and the
     * only safe one for a third-party driver.
     */
    public function testADriverWithoutACatalogueIsNotSecondGuessed(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $candidate = new Candidate('custom', 'Custom', $driver, 'whatever-model');

        $this->assertTrue($this->evaluate($candidate)->isEligible);
    }

    public function testTheRejectionNamesTheDriverTheModelAndTheAlternatives(): void
    {
        $driver = new RecordingDriver('groq', ['llama-3.3-70b-versatile']);
        $eval = $this->evaluate(new Candidate('groq', 'Groq', $driver, 'gpt-5'));

        $rejection = $eval->rejections[0];
        $this->assertSame('CandidateModelConstraint', $rejection->constraintName);
        $this->assertSame('candidate_model_unsupported', $rejection->reasonCode);
        $this->assertSame('gpt-5', $rejection->context['candidate_model']);
        $this->assertSame('groq', $rejection->context['driver_id']);
        $this->assertSame(['llama-3.3-70b-versatile'], $rejection->context['supported_models']);
    }

    /**
     * The check must agree with the driver it checks. A real priced driver
     * strips a provider prefix when resolving, so a constraint answering from
     * array_keys(PRICING) would reject a candidate the driver serves happily.
     * Asking supportsModel() cannot drift, because it is the same code path.
     */
    public function testAPrefixedModelNameIsAcceptedExactlyAsTheDriverWouldAcceptIt(): void
    {
        $groq = new GroqDriver(new HttpClient(), groqApiKey: 'test-key');

        $this->assertTrue($groq->supportsModel('llama-3.3-70b-versatile'));
        $this->assertTrue($groq->supportsModel('groq/llama-3.3-70b-versatile'));
        $this->assertFalse($groq->supportsModel('gpt-5'));

        $prefixed = new Candidate('groq', 'Groq', $groq, 'groq/llama-3.3-70b-versatile');
        $this->assertTrue($this->evaluate($prefixed)->isEligible);
    }

    /**
     * Ollama resolves loosely against whatever is installed and falls back
     * when nothing matches, so it turns no name away — and answers without the
     * HTTP call that reading its catalogue would cost.
     */
    public function testOllamaIsNeverRejectedAndNeverPerformsIo(): void
    {
        $ollama = new OllamaDriver(new HttpClient(), 'http://127.0.0.1:1');

        $this->assertTrue($ollama->supportsModel('anything-at-all'));
        $this->assertTrue($this->evaluate(new Candidate('ollama', 'Ollama', $ollama, 'llama3:8b'))->isEligible);
    }
}
