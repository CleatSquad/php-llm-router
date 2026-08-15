# php-llm-router v5.0.2 — Release Memory & Notes

## Released Features & Fixes in v5.0.2

1. **Ranker Score Overwrite Fix**:
   - `RoutingEngine` composes multiple policy rankers via `CompositeRanker` with equal weights (1.0).
   - Aggregated weighted score calculation preserves per-ranker breakdown in `RankScore::$metadata`.

2. **Ranker Failure Isolation**:
   - Exception handling during candidate scoring wraps individual evaluations.
   - Faulty candidate is rejected with structured telemetry (`constraintName` = `'RankerException'`, `reasonCode` = `'ranker_error'`).
   - Other candidates remain eligible and evaluation proceeds without crashing.

3. **Factory & Tracker Mappings**:
   - Added `LeastBusyRanker` and `UsageRanker`.
   - Connected `RoutingStrategyFactory` strategies (`least-busy`, `usage`, `reliability`, `quota`) to their respective trackers (`activeRequestsTracker`, `usageTracker`, `reliabilityTracker`, `quotaTracker`).

4. **Documentation & Unit Test Coverage**:
   - Added unit tests for multi-rankers, failure isolation, factory tracker integration, selectors, constraints, and adapters.
   - Updated `CHANGELOG.md`, `UPGRADE.md`, `README.md`, `docs/v5-migration.md`, and `docs/routing-strategies.md`.

5. **v5.1 Deferred Scope**:
   - Model-aware Candidate, ModelConstraint, ObservedDriver, RoutingExecutor, and telemetry redesign remain deferred to v5.1.
