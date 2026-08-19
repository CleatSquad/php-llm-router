<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Execution;

use CleatSquad\LlmRouter\Contract\Exception\RoutingFailureInterface;
use CleatSquad\LlmRouter\Decision\RoutingDecision;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Exception\AllCandidatesFailedException;
use Generator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs a RoutingDecision. Does not make one.
 *
 * The rule, in full: it may skip a candidate; it may never add one, reorder
 * them, or substitute a model. Skipping is what keeps a pre-computed plan
 * honest — the plan is a snapshot, and by the third fallback a circuit breaker
 * may have opened on a candidate that looked fine when it was taken.
 *
 * Deliberately not an LLMDriverInterface: that interface carries an LLMRequest
 * and nothing more, so implementing it would mean re-deriving the plan instead
 * of receiving it. Decorators belong under each candidate's driver.
 *
 * See UPGRADE.md (5.1 → 5.2) for why FailoverDriver could not do this.
 */
final class PlanExecutor
{
    /** @var callable(Throwable, Candidate): bool */
    private $shouldFailover;

    /**
     * @param callable(Throwable, Candidate): bool|null $shouldFailover
     *   Whether a failure moves to the next candidate (true) or propagates
     *   (false). Defaults to moving on for anything except a
     *   RoutingFailureInterface: burning the remaining candidates on a broken
     *   plan replaces an accurate diagnosis with a misleading one.
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        ?callable $shouldFailover = null,
    ) {
        $this->shouldFailover = $shouldFailover
            ?? static fn (Throwable $e, Candidate $c): bool => !$e instanceof RoutingFailureInterface;
    }

    /**
     * @param callable(Candidate): void|null $onServed Handed the candidate
     *   that actually answered, mirroring stream()'s callback of the same name.
     * @param callable(Candidate, Throwable): void|null $onFailure Handed every
     *   candidate that failed along the way, including one a later candidate
     *   then served for — a mid-plan failure is still a real failure, not
     *   erased by the fallback succeeding.
     * @throws AllCandidatesFailedException when no candidate answered.
     */
    public function execute(
        LLMRequest $request,
        RoutingDecision $decision,
        ?callable $onServed = null,
        ?callable $onFailure = null,
    ): LLMResponse {
        $failures = [];
        $skipped = [];

        foreach ($decision->getCandidates() as $candidate) {
            if (!$candidate->driver->isAvailable()) {
                $skipped[] = $candidate;
                $this->logSkip($candidate);
                continue;
            }

            try {
                $response = $candidate->driver->chat($this->requestFor($request, $candidate));

                if ($onServed !== null) {
                    $onServed($candidate);
                }

                return $response;
            } catch (RuntimeException $e) {
                if (!($this->shouldFailover)($e, $candidate)) {
                    throw $e;
                }

                $failures[] = ['candidate' => $candidate, 'exception' => $e];
                $this->logFailure($candidate, $e);

                if ($onFailure !== null) {
                    $onFailure($candidate, $e);
                }
            }
        }

        throw $this->exhausted($failures, $skipped);
    }

    /**
     * Fails over only before the first chunk reaches the caller: a fragment
     * already yielded cannot be un-yielded, so switching candidates after that
     * point would splice two providers' output into one reply.
     *
     * @param callable(Candidate): void|null $onServed Handed the candidate that
     *   actually answered. The generator's return value is already taken by the
     *   driver's tool calls, and a caller that must name the model it received
     *   cannot re-derive it from the plan: after a failover, the candidate that
     *   answered is not the one the plan selected.
     * @param callable(Candidate, Throwable): void|null $onFailure Handed every
     *   candidate that failed before any chunk of its own was yielded — the
     *   only case that fails over rather than propagating.
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     * @throws AllCandidatesFailedException when no candidate answered.
     */
    public function stream(
        LLMRequest $request,
        RoutingDecision $decision,
        ?callable $onServed = null,
        ?callable $onFailure = null,
    ): Generator {
        $failures = [];
        $skipped = [];

        foreach ($decision->getCandidates() as $candidate) {
            if (!$candidate->driver->isAvailable()) {
                $skipped[] = $candidate;
                $this->logSkip($candidate);
                continue;
            }

            $yieldedAny = false;

            try {
                $inner = $candidate->driver->stream($this->requestFor($request, $candidate));
                foreach ($inner as $chunk) {
                    $yieldedAny = true;
                    yield $chunk;
                }

                if ($onServed !== null) {
                    $onServed($candidate);
                }

                return $inner->getReturn();
            } catch (RuntimeException $e) {
                if ($yieldedAny || !($this->shouldFailover)($e, $candidate)) {
                    throw $e;
                }

                $failures[] = ['candidate' => $candidate, 'exception' => $e];
                $this->logFailure($candidate, $e);

                if ($onFailure !== null) {
                    $onFailure($candidate, $e);
                }
            }
        }

        throw $this->exhausted($failures, $skipped);
    }

    /**
     * The candidate's own model, or the request untouched when it names none.
     * A candidate never inherits another's model, and never has its own
     * replaced after a failure.
     */
    private function requestFor(LLMRequest $request, Candidate $candidate): LLMRequest
    {
        return $candidate->model !== null
            ? $request->withModel($candidate->model)
            : $request;
    }

    /**
     * @param array<int, array{candidate: Candidate, exception: Throwable}> $failures
     * @param array<int, Candidate> $skipped
     */
    private function exhausted(array $failures, array $skipped): AllCandidatesFailedException
    {
        $exception = new AllCandidatesFailedException($failures, $skipped);

        $this->logger?->error('llm_router.plan_exhausted', [
            'attempted' => count($failures),
            'skipped' => count($skipped),
            'failures' => array_map(
                static fn (array $f): array => [
                    'candidate_id' => $f['candidate']->id,
                    'model' => $f['candidate']->model,
                    'error' => self::redactSecrets($f['exception']->getMessage()),
                ],
                $failures
            ),
        ]);

        return $exception;
    }

    private function logSkip(Candidate $candidate): void
    {
        $this->logger?->notice('llm_router.candidate_skipped', [
            'candidate_id' => $candidate->id,
            'model' => $candidate->model,
            'reason' => 'driver reported unavailable at its turn in the plan',
        ]);
    }

    private function logFailure(Candidate $candidate, Throwable $e): void
    {
        $this->logger?->notice('llm_router.candidate_failed', [
            'candidate_id' => $candidate->id,
            'model' => $candidate->model,
            'error' => self::redactSecrets($e->getMessage()),
        ]);
    }

    /**
     * Guzzle quotes the whole URL in its exception messages. A driver
     * authenticating by query parameter would leak its key here (RFC-0070, I-5).
     */
    private static function redactSecrets(string $message): string
    {
        return preg_replace(
            '/([?&](?:key|api_key|apikey|access_token|token)=)[^&\s\'"]+/i',
            '$1***',
            $message
        ) ?? $message;
    }
}
