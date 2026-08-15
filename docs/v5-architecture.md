# php-llm-router v5 Architecture Documentation

## Overview

`php-llm-router` v5 provides a **Composable Routing Decision Engine** (`RoutingEngine`). Routing decisions are constructed by evaluating an array of candidate drivers against a composable `RoutingPolicy` consisting of:

1. **Constraints** (`ConstraintInterface`): Hard filtering (e.g. capabilities, context window, quota limits).
2. **Rankers** (`RankerInterface`): Scoring and metric computation returning rich `RankScore` objects.
3. **Selectors** (`SelectorInterface`): Final candidate ordering (`Candidate[]`).

---

## Core Abstractions & Pipeline

```
LLMRequest + LLMDriverInterface[]
              ↓
      Candidate Discovery (Immutable Candidate Value Objects)
              ↓
     CandidateEvaluation Context Initialization
              ↓
       Hard Constraints Filtering (ConstraintInterface)
              ↓
          Ranking & Scoring (RankerInterface -> RankScore)
              ↓
         Candidate Ordering (SelectorInterface -> Candidate[])
              ↓
          RoutingDecision (Inspectable Telemetry Object)
```

### 1. `Candidate` & `CandidateEvaluation`
- `Candidate` (Immutable Value Object): Wraps `id`, `name`, and `LLMDriverInterface`.
- `CandidateEvaluation` (Evaluation State): Tracks accumulative `rejections`, `score`, and `isEligible` eligibility status.

### 2. `RankScore`
Carries a `float $value`, `string $ranker`, and diagnostic `array $metadata`.

### 3. `RoutingDecision`
Exposes `$selected`, `$orderedCandidates`, `$evaluations`, `$policyName`, and `$decision->getFallbacks()`.

### 4. `NoEligibleCandidateException`
Thrown when no candidate survives constraints. Carries `$exception->getEvaluations()` containing complete rejection telemetry.
