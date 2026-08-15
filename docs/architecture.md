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
                   Selected Driver
                          │
                          ▼
                 Resilience Decorators
       (Failover, CircuitBreaker, Retrying, RateLimited)
                          │
                          ▼
                     LLM Execution
               (chat() / stream() call)
                          │
                          ▼
                   Metrics & Tracking
       (Latency, Reliability, ActiveRequests, Usage)
```

---

## Component Responsibilities

1. **Routing Strategy** (`RoutingStrategyInterface`):
   * *Answers*: "Which available candidate deployment should be selected for this request?"
   * *Scope*: Pure candidate selection based on rules, constraints, metrics, or preferences.
   * *Does not*: Perform HTTP calls, retry failed requests, or handle exceptions.

2. **Resilience & Execution** (`FailoverDriver`, `RetryingDriver`, `CircuitBreakerDriver`, `RateLimitedDriver`):
   * *Answers*: "How to execute the request reliably, handle transient errors, limit rates, and fail over if necessary?"
   * *Scope*: Intercepting driver calls and applying failure recovery patterns.

3. **Metrics & Trackers** (`*TrackerInterface`):
   * *Answers*: "How to observe latency, active requests, reliability success rates, and usage over time?"
   * *Scope*: Recording state across processes or in-memory, fully decoupled from concrete storage engines.
