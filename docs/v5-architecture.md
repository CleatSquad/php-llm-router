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
- `Candidate` (Immutable Value Object): Wraps `id` (deployment identity), `name`, `LLMDriverInterface` (execution driver), and optional `model` (assigned model capability).
- `CandidateEvaluation` (Evaluation State): Tracks accumulative `rejections`, `score`, and `isEligible` eligibility status.

### Candidate Identity & Model Capability (v5.1.0)
- **Deployment Identity (`Candidate::$id`)**: Unique identifier per deployment/node (e.g. `'ollama-node-1'`, `'azure-east-gpt4o'`). For persistent multi-deployment isolation, explicit `Candidate` instances should be passed to `RoutingEngine::decide()`. Raw drivers with duplicate IDs within a single decision receive local suffix IDs (`'ollama'`, `'ollama#1'`).
- **Model Capability (`Candidate::$model`)**: Specific model assigned to a deployment. `ModelConstraint` strictly matches `LLMRequest::$model` against `Candidate::$model` when populated, falling back to `$driver->getModels()` only when `Candidate::$model` is `null`.
- **Policy Map Fallback**: Hierarchical lookup (`Candidate::$id` -> `Candidate::$model` -> `$driver->getId()`) is supported in `ContextWindowConstraint` and `PriorityRanker`. `WeightedSelector` remains strictly deployment-specific (`Candidate::$id` only).

### 2. `RankScore`
Carries a `float $value`, `string $ranker`, and diagnostic `array $metadata`.

### 3. `RoutingDecision`
Exposes `$selected`, `$orderedCandidates`, `$evaluations`, `$policyName`, and `$decision->getFallbacks()`. Telemetry (`toArray()`) exposes both `candidate_id` and `candidate_model`.

### 4. `NoEligibleCandidateException`
Thrown when no candidate survives constraints. Carries `$exception->getEvaluations()` containing complete rejection telemetry.
