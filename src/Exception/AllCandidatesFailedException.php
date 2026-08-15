<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Exception;

use CleatSquad\LlmRouter\Contract\Exception\ExecutionFailureInterface;
use CleatSquad\LlmRouter\Engine\Candidate;
use RuntimeException;
use Throwable;

/**
 * Every candidate in the plan was attempted and every one failed.
 *
 * Distinct from AllDriversFailedException, which reports driver IDs only:
 * "groq failed" and "groq serving llama-3.3-70b-versatile failed" answer
 * different questions, and only the second can be acted on.
 *
 * An ExecutionFailureInterface — reaching it means the plan was sound and the
 * providers were not. A broken plan raises a RoutingFailureInterface earlier.
 */
final class AllCandidatesFailedException extends RuntimeException implements ExecutionFailureInterface
{
    /**
     * @param array<int, array{candidate: Candidate, exception: Throwable}> $failures
     * @param array<int, Candidate> $skipped Candidates never attempted because
     *   they reported themselves unavailable at their turn.
     */
    public function __construct(
        private readonly array $failures,
        private readonly array $skipped = [],
    ) {
        $described = array_map(
            static fn (array $f): string => sprintf(
                '%s (%s): %s',
                $f['candidate']->id,
                $f['candidate']->model ?? 'driver default model',
                $f['exception']->getMessage(),
            ),
            $failures
        );

        if ($skipped !== []) {
            $described[] = sprintf(
                'skipped as unavailable: %s',
                implode(', ', array_map(static fn (Candidate $c): string => $c->id, $skipped))
            );
        }

        parent::__construct(
            'Every candidate in the routing plan failed: ' . implode(' | ', $described)
        );
    }

    /**
     * @return array<int, array{candidate: Candidate, exception: Throwable}>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * @return array<int, Candidate>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }
}
