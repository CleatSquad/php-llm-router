<?php

declare(strict_types=1);

namespace LlmRouter\Exception;

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
 * Deliberately a RuntimeException, so FailoverDriver treats it as a failure
 * worth failing over from: in a mixed chain, the driver that doesn't know a
 * model is exactly the one that should step aside for the one that does.
 */
final class UnknownModelException extends RuntimeException
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
