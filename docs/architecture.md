# `cleatsquad/php-llm-router` — Architecture

How a request becomes a routing decision, and how that decision is carried out.
This is the current design (v5). Coming from v4, read
[v5-migration.md](v5-migration.md) first — it maps every v4 strategy to its v5
equivalent.

## Design principles

The library is built on **composition**, **single responsibility** and **zero
global state**. Each component in the request lifecycle has one clear job and
does not leak concerns into its neighbours.

It separates two things that are usually tangled: **routing** — deciding *who*
to call, before sending anything — and **failure handling** — retries,
fallbacks, circuit breakers, rate limits.

## Request lifecycle

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
                Ranking and Selection
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
                   Metrics and Tracking
       (Latency, Reliability, ActiveRequests, Usage)
```

**The decision is the plan.** Nothing downstream of `RoutingDecision` chooses a
driver, a model or a fallback — it executes the candidates the decision
produced. That boundary is load-bearing: the previous design re-decided at
execution time from bare drivers, which silently discarded each candidate's
model and the constraints it had passed.

When a call fails, the failure path stays inside the plan: the executor moves to
the next candidate the policy already approved, while `RetryingDriver` and
`CircuitBreakerDriver` handle transient errors beneath a single candidate.

## The routing policy

`RoutingEngine` evaluates candidate drivers against a composable
`RoutingPolicy` made of three kinds of parts:

| Part | Interface | Role |
|---|---|---|
| **Constraints** | `ConstraintInterface` | hard filtering — capabilities, context window, quota |
| **Rankers** | `RankerInterface` | scoring, returning a `RankScore` |
| **Selectors** | `SelectorInterface` | final ordering, returning `Candidate[]` |

```text
LLMRequest + LLMDriverInterface[]
              ↓
      Candidate discovery (immutable value objects)
              ↓
      CandidateEvaluation context initialisation
              ↓
      Hard constraint filtering      (ConstraintInterface)
              ↓
      Ranking and scoring            (RankerInterface → RankScore)
              ↓
      Candidate ordering             (SelectorInterface → Candidate[])
              ↓
      RoutingDecision                (inspectable telemetry object)
```

### `Candidate` and `CandidateEvaluation`

`Candidate` is an immutable value object wrapping `id` (deployment identity),
`name`, the `LLMDriverInterface` that executes, and an optional `model`.
`CandidateEvaluation` carries the evaluation state: accumulated `rejections`,
`score`, and `isEligible`.

**Deployment identity — `Candidate::$id`.** Unique per deployment or node
(`'ollama-node-1'`, `'azure-east-gpt4o'`). For persistent multi-deployment
isolation, pass explicit `Candidate` instances to `RoutingEngine::decide()`; raw
drivers sharing an id within one decision receive local suffixes (`'ollama'`,
`'ollama#1'`).

**Model capability — `Candidate::$model`.** The model assigned to a deployment.
`ModelConstraint` matches `LLMRequest::$model` against `Candidate::$model`
strictly when it is populated, and falls back to `$driver->getModels()` only
when it is `null`.

**Policy map fallback.** `ContextWindowConstraint` and `PriorityRanker` look up
hierarchically: `Candidate::$id` → `Candidate::$model` → `$driver->getId()`.
`WeightedSelector` stays strictly deployment-specific — `Candidate::$id` only.

### `RankScore`

A `float $value`, the `string $ranker` that produced it, and diagnostic
`array $metadata`.

### `RoutingDecision`

Exposes `$selected`, `$orderedCandidates`, `$evaluations`, `$policyName` and
`getFallbacks()`. Its telemetry (`toArray()`) carries both `candidate_id` and
`candidate_model`.

### `NoEligibleCandidateException`

Thrown when no candidate survives the constraints. It carries
`getEvaluations()` — the complete rejection telemetry, so a routing failure is
diagnosable without re-running it.

## Component responsibilities

**Routing engine** — `RoutingEngine` + `RoutingPolicy`. Answers *which
candidates can serve this request, and in what order?* It filters, ranks and
orders, producing a `RoutingDecision`: the complete plan, each `Candidate`
pairing a driver with the model it was resolved for. It performs no HTTP call,
retries nothing, handles no exception.

> `RoutingStrategyInterface` is the deprecated v4 form. It returns an
> `LLMDriverInterface`, which cannot carry a candidate's model or its
> evaluation.

**Plan execution** — `PlanExecutor`. Answers *how is this decision carried
out?* It walks the candidates in order, serves each its own model, and moves on
when a provider fails. It may skip a candidate whose driver reports itself
unavailable at its turn — a filter over an existing plan, which is what keeps a
circuit breaker that opened mid-run from being ignored. It never adds a
candidate, reorders them, or substitutes a model.

> `FailoverDriver` is the deprecated form. It held bare drivers and a second
> strategy, so it decided as well as executed — losing the model and the
> constraints on the way.

**Resilience decorators** — `RetryingDriver`, `CircuitBreakerDriver`,
`RateLimitedDriver`, `CachingDriver`. Answer *how is one candidate's call made
reliable?* They intercept driver calls beneath a candidate, never above the
executor.

**Metrics and trackers** — the `*TrackerInterface` family. Answer *how are
latency, active requests, reliability and usage observed?* They record state in
memory or across processes, fully decoupled from any concrete storage engine.

## Available strategies

| Strategy | Type | Metric required | Purpose |
|---|---|---|---|
| Priority | ranking | no | explicit preference |
| Random | ranking | no | random distribution |
| Weighted | ranking | no | weighted distribution |
| RoundRobin | distribution | state | rotation |
| LeastBusy | ranking | active requests | load balancing |
| Usage | ranking | usage | usage distribution |
| Latency | ranking | latency | performance |
| Cost | ranking | pricing | cost optimisation |
| Reliability | ranking | reliability | availability |
| ContextWindow | constraint | model metadata | context compatibility |
| Capability | constraint | capabilities | feature compatibility |
| Quota | constraint | quota state | quota protection |

Notes on individual strategies:

- **Priority** — explicit preference, plus a per-request switch through
  `$request->preferQuality` (`qualityPriorities`).
- **Weighted and Random** — probabilistic load balancing. The source of
  randomness sits behind `RandomizerInterface`, so unit tests stay
  deterministic.
- **LeastBusy** — reads through `ActiveRequestsTrackerInterface` rather than
  requiring a global Redis.
- **Latency** — a moving average kept by `LatencyTrackerInterface`. Cover the
  warm-up window with a configurable `defaultLatencyMs`.
- **Cost** — decided before the call, from `$driver->estimateCost($request)`.

## What this library deliberately does not do

Compared with a router that runs as a service:

- **No global proxy state, no central daemon.** This is a library you embed —
  no extra network hop, no second process to operate.
- **No hardcoded pricing database.** Prices are not baked into the cost
  strategy; each driver resolves them from its own model catalogue through
  `estimateCost()`.

The result is an extensible set of LLM routing strategies for PHP, strictly
typed against PHP 8.2+, testable without any global dependency.
