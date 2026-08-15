# `cleatsquad/php-llm-router` Architecture Overview

## Design Principles

`cleatsquad/php-llm-router` is designed around **Composition**, **Single Responsibility**, and **Zero Global State**.

Each component in the request lifecycle has a clear responsibility and does not leak concerns to neighboring abstractions.

---

## Request Lifecycle Model

```text
                     LLM Request
                          │
                          ▼
            Candidate LLM Driver List
                          │
                          ▼
               Availability Filtering
               ($driver->isAvailable())
                          │
                          ▼
               Hard Constraints Filtering
           (Capability, ContextWindow, Quota)
                          │
                          ▼
                Routing Strategy Ranking
       (Priority, Cost, Latency, Reliability,
        LeastBusy, Usage, Weighted, Random)
                          │
                          ▼
                  RoutingDecision
        (ordered Candidates: driver + model,
         each having passed every constraint)
                          │
                          ▼
                    PlanExecutor
      (walks the plan; may skip a candidate,
       never adds, reorders or re-models one)
                          │
                          ▼
                 Resilience Decorators
        (CircuitBreaker, Retrying, RateLimited)
                          │
                          ▼
                     LLM Execution
               (chat() / stream() call)
                          │
                          ▼
                   Metrics & Tracking
       (Latency, Reliability, ActiveRequests, Usage)
```

The decision is the plan. Nothing downstream of `RoutingDecision` chooses a
driver, a model or a fallback — it executes the candidates the decision
produced. That boundary is load-bearing: the previous design re-decided at
execution time from bare drivers, which silently discarded each candidate's
model and the constraints it had passed.

---

## Component Responsibilities

1. **Routing Engine** (`RoutingEngine` + `RoutingPolicy`):
   * *Answers*: "Which candidates can serve this request, and in what order?"
   * *Scope*: Constraint filtering, ranking and ordering, producing a `RoutingDecision` — the complete plan, each `Candidate` pairing a driver with the model it was resolved for.
   * *Does not*: Perform HTTP calls, retry failed requests, or handle exceptions.
   * *(`RoutingStrategyInterface` is the deprecated v4 form of this. It returns an `LLMDriverInterface`, which cannot carry a candidate's model or its evaluation.)*

2. **Plan Execution** (`PlanExecutor`):
   * *Answers*: "How is this decision carried out?"
   * *Scope*: Walking the decision's candidates in order, serving each its own model, moving on when a provider fails. It may skip a candidate whose driver reports itself unavailable at its turn — a filter over an existing plan, which is what keeps a circuit breaker that opened mid-run from being ignored.
   * *Does not*: Decide. It never adds a candidate, reorders them, or substitutes a model.
   * *(`FailoverDriver` is the deprecated form. It held bare drivers and a second strategy, so it decided as well as executed — and lost the model and the constraints doing so.)*

3. **Resilience Decorators** (`RetryingDriver`, `CircuitBreakerDriver`, `RateLimitedDriver`, `CachingDriver`):
   * *Answers*: "How to make one candidate's call reliable — transient errors, rate limits, open circuits?"
   * *Scope*: Intercepting driver calls beneath a candidate, never above the executor.

4. **Metrics & Trackers** (`*TrackerInterface`):
   * *Answers*: "How to observe latency, active requests, reliability success rates, and usage over time?"
   * *Scope*: Recording state across processes or in-memory, fully decoupled from concrete storage engines.
