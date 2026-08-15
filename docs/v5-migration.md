# php-llm-router v5 Migration Guide

## Migrating from v4 Strategy-based Routing to v5 Decision Engine

### Strategy Mapping

| v4 Strategy | v5 Composable Component Equivalent |
| :--- | :--- |
| `PriorityStrategy` | `PriorityRanker` + `BestCandidateSelector` |
| `LeastBusyStrategy` | `LeastBusyRanker` + `BestCandidateSelector` |
| `UsageStrategy` | `UsageRanker` + `BestCandidateSelector` |
| `CapabilityStrategy` | `CapabilityConstraint` |
| `ContextWindowStrategy` | `ContextWindowConstraint` |
| `CostStrategy` | `CostRanker` + `BestCandidateSelector` |
| `LatencyStrategy` | `LatencyRanker` + `BestCandidateSelector` |
| `ReliabilityStrategy` | `ReliabilityRanker` + `BestCandidateSelector` |
| `QuotaStrategy` | `QuotaConstraint` |
| `WeightedStrategy` | `WeightedSelector` |
| `RoundRobinStrategy` | `RoundRobinSelector` |

### v5 Policy Example

```php
use CleatSquad\LlmRouter\Engine\RoutingEngine;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use CleatSquad\LlmRouter\Constraint\CapabilityConstraint;
use CleatSquad\LlmRouter\Constraint\ContextWindowConstraint;
use CleatSquad\LlmRouter\Ranker\CompositeRanker;
use CleatSquad\LlmRouter\Ranker\CostRanker;
use CleatSquad\LlmRouter\Ranker\LatencyRanker;
use CleatSquad\LlmRouter\Selector\BestCandidateSelector;

$policy = new RoutingPolicy(
    constraints: [
        new CapabilityConstraint(requireTools: true),
        new ContextWindowConstraint(),
    ],
    rankers: [
        new CompositeRanker([
            ['ranker' => new LatencyRanker($latencyTracker), 'weight' => 0.60],
            ['ranker' => new CostRanker(), 'weight' => 0.40],
        ]),
    ],
    selector: new BestCandidateSelector()
);

$engine = new RoutingEngine($policy);
$decision = $engine->decide($request, [$driverA, $driverB]);
$selectedDriver = $decision->selected->driver;
```
