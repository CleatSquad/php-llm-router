<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Exception;

use CleatSquad\LlmRouter\Contract\Exception\RoutingFailureInterface;
use RuntimeException;

/**
 * Thrown when a request names a model the driver has no pricing for.
 *
 * Drivers used to answer such a request with their own default model instead:
 * asking OpenAiDriver for "gpt-5" silently got you "gpt-4o-mini", billed and
 * reported as if you had asked for it. That is the failure mode this exception
 * exists to end — a wrong model is worse than no answer, because nothing in
 * the response reveals the substitution.
 *
 * A RoutingFailureInterface: a driver holding an instruction it cannot read is
 * a defect in whatever paired them, not an outage, so PlanExecutor surfaces it
 * instead of moving down the plan. The deprecated FailoverDriver fails over on
 * it, as it always has — which works only while some other candidate knows the
 * model, and not at all when it is provider-exclusive.
 *
 * Still a RuntimeException: existing catch sites are unaffected.
 */
final class UnknownModelException extends RuntimeException implements RoutingFailureInterface
{
    /**
     * @param string[] $knownModels
     */
    public function __construct(
        public readonly string $driverId,
        public readonly string $requestedModel,
        public readonly array $knownModels,
    ) {
        parent::__construct(sprintf(
            '"%s" is not a model %s knows about. Known models: %s. '
            . 'Pass its pricing through the driver\'s $extraModelPricing argument '
            . 'to use a model this version predates, or request one of the above.',
            $requestedModel,
            $driverId,
            $knownModels === [] ? '(none configured)' : implode(', ', $knownModels),
        ));
    }
}
