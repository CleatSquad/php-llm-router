# Routing Strategies in `cleatsquad/php-llm-router`

## Overview

`cleatsquad/php-llm-router` provides a native PHP LLM routing library inspired by proven multi-deployment routing patterns.

It cleanly separates **Routing** (deciding *who* to call before sending a request) from **Failure Handling** (retries, failovers, circuit breakers, rate limiting).

---

## Architecture & Responsibilities

```text
                      RoutingEngine
                           │
                    RoutingPolicy
                           │
             ┌─────────────┼─────────────┐
             ▼             ▼             ▼
       Priority     Weighted / Random   Metrics-driven
        Ranker         Selectors      (LeastBusy, Latency, Cost)
                           │
                           ▼
            RoutingDecision → PlanExecutor
             (ordered Candidates: driver + model)
                           │
                      ┌────▼────┐
                      │ Request │
                      └────┬────┘
                           │
                      failure ?
                      /         \
                    no           yes
                    │             │
                    ▼             ▼
                  result       Retry / Fallback / CircuitBreaker
```

---

## Available Strategies

| Strategy      | Type         | Metric required | Purpose               |
| ------------- | ------------ | --------------- | --------------------- |
| Priority      | ranking      | no              | explicit preference   |
| Random        | ranking      | no              | random distribution   |
| Weighted      | ranking      | no              | weighted distribution |
| RoundRobin    | distribution | state           | rotation              |
| LeastBusy     | ranking      | active requests | load balancing        |
| Usage         | ranking      | usage           | usage distribution    |
| Latency       | ranking      | latency         | performance           |
| Cost          | ranking      | pricing         | cost optimization     |
| Reliability   | ranking      | reliability     | availability          |
| ContextWindow | constraint   | model metadata  | context compatibility |
| Capability    | constraint   | capabilities    | feature compatibility |
| Quota         | constraint   | quota state     | quota protection      |

---

## Conceptual Comparison with LiteLLM Router

### 1. Architectural Philosophy
* **LiteLLM**: one Router object carries load balancing, failover, retries, budget tracking, API key management and an async Python HTTP proxy.
* **php-llm-router**: decorators and plain PHP composition. Routing is a policy of small objects (constraints, rankers, selectors) producing a `RoutingDecision`; executing that decision — including fail-over across its candidates — lives in `PlanExecutor`, circuit breaking in `CircuitBreakerDriver`, rate limiting in `RateLimitedDriver`, each usable on its own. The split is deliberate: the executor never re-decides, so a fallback is always a candidate the policy itself approved, served the model it was resolved for.

### 2. Strategy Parity & Differences
* **Priority Strategy**: modeled on LiteLLM's priority routing, plus a per-request switch through `$request->preferQuality` (`qualityPriorities`).
* **Weighted & Random Strategies**: probabilistic load balancing. The source of randomness sits behind `RandomizerInterface`, so unit tests are deterministic.
* **LeastBusy Strategy**: comparable to LiteLLM `least-busy`, but reads through `ActiveRequestsTrackerInterface` instead of requiring a global Redis.
* **Latency Strategy**: a moving average kept by `LatencyTrackerInterface`. Cover the warm-up window with a configurable `defaultLatencyMs`.
* **Cost Strategy**: decided before the call, from `$driver->estimateCost($request)`.

### 3. Intentional Omissions & Why
* **Global proxy state / central daemon**: LiteLLM runs as a standalone Python proxy server. This is a library you embed, with no extra network hop and no second process to operate.
* **Hardcoded pricing databases**: prices are not baked into the cost strategy. Each driver resolves them from its own model catalogue through `estimateCost()`.

---

## Conclusion

The result is a complete, extensible set of LLM routing strategies for PHP, strictly typed against PHP 8.2+ and testable without any global dependency.
