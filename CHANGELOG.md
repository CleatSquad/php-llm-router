# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The 1.x entries are reconstructed from the git history rather than from
releases that actually existed — only v1.12.0 … v1.13.1 were ever tagged — so
they group commits by theme. Where a commit message does not say *why* a change
was made, this file says so instead of guessing.

The 4.x entries carry no date. They were tagged within a day of each other, and
a date on each would say less about this package than the tags do. For the
release date of any version, ask git: `git log -1 --format=%ad v4.1.3`.

## [5.3.0] - 2026-08-17

5.2 made the routing decision the only plan there is. This release fixes four
ways the layers underneath it still contradicted that plan, gave up on it too
late, or wrote a credential into a log while doing so.

No breaking changes.

### Added

- **`ModelCatalogueInterface` on the four decorators** — `CachingDriver`,
  `CircuitBreakerDriver`, `RateLimitedDriver` and `RetryingDriver` now
  implement it and delegate `supportsModel()` to what they wrap. A caller only
  ever holds the outermost wrapper, so a decorator that dropped the interface
  made a fully catalogued driver answer as if it had none — and
  `CandidateModelConstraint`, which exists to reject a candidate paired with a
  model it cannot serve, silently stopped rejecting anything for every
  decorated driver. Wrapping a driver that answers no catalogue question keeps
  the documented default: it is assumed able to serve what it is given.

### Fixed

- **`RetryingDriver` stops retrying when `Retry-After` exceeds
  `$maxDelaySeconds`.** The header says when the quota frees up, and not
  before; capping the wait never made it come back sooner. The driver waited
  the full cap, earned another 429, and repeated that for every remaining
  attempt — spending the caller's whole budget on a foregone conclusion, at the
  one moment a router needed time to reach its next candidate. A cooldown this
  driver cannot wait out now propagates immediately. A `Retry-After` within the
  budget is honoured exactly as before.

### Security

- **`GeminiDriver` authenticates with the `x-goog-api-key` header** instead of
  a `key` query parameter, on all three endpoints (`/models`, chat, streaming).
  A key in a URL travels wherever the URL does — proxy access logs, Guzzle
  exception messages, anything that quotes the request line. Google documents
  the header for that reason and accepts it everywhere this driver calls. Same
  credential, same constructor argument: nothing to change at the call site.
- **`PlanExecutor` redacts URL credentials from failure messages.** Guzzle
  quotes the whole URL in its exception messages, and the executor copied those
  into `llm_router.candidate_failed` and into the exhaustion report. Any driver
  still authenticating by query parameter — including one written outside this
  package — wrote its key there. Values of `key`, `api_key`, `apikey`,
  `access_token` and `token` are replaced with `***`.

### Testing

- The Redis-backed suites declare `#[RequiresPhpExtension('redis')]` and skip
  where the extension is absent, instead of erroring in a way that read as a
  failure of the store under test.
- `DecoratorPreservesCatalogueTest` asserts the catalogue contract across all
  four decorators, so a fifth one cannot reintroduce the same gap unnoticed.

## [5.2.0] - 2026-08-15

Routing decided a plan; execution ignored it and built its own. This release
makes the decision the only plan there is.

`RoutingEngine` already produced the full ordered, constraint-checked candidate
list, each candidate carrying its own driver *and* model. Nothing executed it.
`FailoverDriver` held bare drivers and a second routing strategy, and re-decided
on every attempt from a poorer model of the world — so a candidate's model and
the constraints it had passed were destroyed at that boundary. Two failures
followed from the one cause:

- Every fallback was handed the *primary* candidate's model. Where a model is
  provider-exclusive, no other driver can serve it, so the whole chain became a
  sequence of validation errors — failing precisely when a fail-over was needed.
- A fallback reached through the second strategy had never been checked against
  the request's requirements, so it could violate a capability or context-window
  constraint the policy had already ruled out — silently, as an opaque provider
  error.

### Added

- **`Execution\PlanExecutor`**: executes a `RoutingDecision` and decides
  nothing. Each candidate is served its own model; no candidate outside the plan
  is ever reached. It may skip a candidate (re-checking `isAvailable()` at that
  candidate's turn, which keeps a circuit breaker that opened mid-run from being
  ignored) but never add one, reorder them, or substitute a model.
  Deliberately **not** an `LLMDriverInterface` — that interface carries only an
  `LLMRequest`, which is why `FailoverDriver` had to re-derive a plan it could
  not receive. Decorators belong under each candidate's driver, as before.
- **`Contract\Exception\RoutingFailureInterface` / `ExecutionFailureInterface`**:
  separate a broken plan from a failing provider. `UnknownModelException`,
  `UnsupportedReasoningException` and `NoEligibleCandidateException` carry the
  first; `RateLimitException` the second. `PlanExecutor` surfaces a routing
  failure instead of failing over on it — trying the next candidate cannot fix
  an impossible instruction, it only spends the rest of the plan hiding the
  cause behind whatever the last candidate happened to say. Marker interfaces,
  so no exception changed its parent class and every existing
  `catch (RuntimeException)` is unaffected.
- **`Contract\Driver\ModelCatalogueInterface`** (`supportsModel()`): lets a
  driver answer whether it would accept a model, using the same code it will use
  at call time. Implemented once in `ResolvesPricedModel` by calling
  `resolveModel()` itself, so a check can never drift from the resolution it
  checks — `groq/llama-3.3-70b-versatile` is accepted, as the driver accepts it,
  where `in_array(getModels())` would wrongly reject it. `OllamaDriver` answers
  `true` unconditionally: its resolution never refuses, and reading its
  catalogue would cost an HTTP call per candidate.
- **`Constraint\CandidateModelConstraint`**: rejects a candidate whose driver
  cannot serve the candidate's own model, before a plan is built. Separate from
  `ModelConstraint`, which asks a different question — "does this candidate
  satisfy what the *caller* asked for?", and correctly stands down when the
  caller asked for nothing. That is the gap the production failure went through:
  the request named no model, so every candidate passed, and the mismatch only
  surfaced once each driver had been handed an instruction it could not read.
- **`Exception\AllCandidatesFailedException`**: reports each attempt as
  candidate *and* model (`groq (llama-3.3-70b-versatile): ...`) plus any
  candidates skipped as unavailable.
- **`LLMRequest::withModel()`**: projects a candidate's model onto a request.
  `$model` on a request remains the caller's requirement; this exists only
  because `LLMDriverInterface` has a single slot a driver reads a model from.
  Not a way to substitute a model after a failure — that is how a caller ends up
  billed for a model it never chose.
- **`RoutingDecision::getCandidates()`**: the plan, selected candidate first.

### Deprecated

- **`Driver\FailoverDriver`** → `Execution\PlanExecutor`. Unchanged and still
  supported: a chain whose candidates all serve the same model behaves exactly
  as documented.
- **`Contract\RoutingStrategyInterface`** → `Engine\RoutingEngine`. The
  deprecation is in the return type: `LLMDriverInterface` cannot carry a
  candidate's model or its evaluation, and no implementation can work around
  that.
- **`Exception\AllDriversFailedException`** → `AllCandidatesFailedException`.

### Notes

No breaking changes. Nothing existing changed behaviour: the new classes are
additions, and the classification of exceptions is by interface only.
`FailoverDriver` keeps failing over on `UnknownModelException` as it always has.

---

## [5.1.0] - 2026-08-15

### Added

- **Model-Aware Candidate Identity & Deployment Separation (RFC-1)**:
  - Extended `Candidate` with optional `public ?string $model = null` for assigned model capabilities.
  - Added `ModelConstraint` (`ConstraintInterface`) for exact, case-sensitive candidate model matching against `LLMRequest::$model`. Explicit `Candidate::$model` takes strict precedence over general driver supported models (`$driver->getModels()`).
  - Added support for passing `Candidate[]` or `LLMDriverInterface[]` to `RoutingEngine::decide()`. Raw drivers with colliding IDs within a single decision receive local suffix IDs (`'ollama'`, `'ollama#1'`).
  - Added hierarchical key lookup fallback (`Candidate::$id` -> `Candidate::$model` -> `$driver->getId()`) to `ContextWindowConstraint` and `PriorityRanker`.
  - Added `candidate_model` telemetry to `RoutingDecision::toArray()`.

---

## [5.0.2] - 2026-08-15

### Fixed

- **Ranker Score Overwrite Fix**: Resolved issue in `RoutingEngine` where consecutive rankers in a policy would overwrite candidate scores. Multiple rankers are now cleanly composed using `CompositeRanker`.
- **Ranker Fault Isolation**: Candidate evaluations now isolate exceptions during ranking/scoring. If a ranker fails for a candidate (e.g. `CostRanker` cost estimation error), the failed candidate is rejected with structured error details (`RankerException`), while remaining candidates continue evaluation.
- **Factory Tracker Mappings**: Restored tracker injection in `RoutingStrategyFactory` for `least-busy`, `usage`, `reliability`, and `quota` strategies.
- **New v5 Rankers**: Added `LeastBusyRanker` and `UsageRanker` to complete metric-driven ranker implementations.

---

## [5.0.0]

### Added

- **Composable Routing Decision Engine (`RoutingEngine`)**: Replaces strategy-based single selection with a policy-driven pipeline.
- **Identity vs State Separation**: Immutable `Candidate` value object for driver identity and `CandidateEvaluation` foraccumulative evaluation state.
- **Explicit Component Contracts**:
  - `ConstraintInterface` (`CapabilityConstraint`, `ContextWindowConstraint`, `QuotaConstraint`): Hard filtering with structured rejections.
  - `RankerInterface` (`PriorityRanker`, `CostRanker`, `LatencyRanker`, `ReliabilityRanker`, `CompositeRanker`): Metric scoring returning rich `RankScore` value objects.
  - `SelectorInterface` (`BestCandidateSelector`, `WeightedSelector`, `RoundRobinSelector`): Candidate ordering producing `Candidate[]`.
- **Explainable Decision Telemetry**: `RoutingDecision` and `CandidateRejection` structured DTOs exposing complete candidate selection, scores, and rejection telemetry.
- **Detailed Exception Telemetry**: `NoEligibleCandidateException` carries `CandidateEvaluation[]` telemetry for all candidates when zero candidates survive constraints.
- **Bidirectional v4 Compatibility Adapters**: `RoutingPolicyAdapter` (v5 policy to v4 strategy) and `LegacyStrategyAdapter` (v4 strategy to v5 policy).
- **Architecture and Migration Documentation**: `docs/v5-architecture.md` and `docs/v5-migration.md`.

---

## [4.1.4]

### Fixed

- **The 4.1.x entries below described the wrong releases.** The strategy suite
  they credited to 4.1.3 shipped in **4.1.1**; 4.1.2 changed no code at all, and
  4.1.3 was a stabilization release. Each entry now matches what its tag
  actually contains. No tag was moved — only this file was wrong.
- Removed `tous`, an empty file committed by accident in the 4.1.3 release
  commit.

### Changed

- `.idea/` is now ignored, and `.idea/modules.xml` is no longer tracked. An IDE
  file has no place in a distributed package.
- `docs/routing-strategies.md` is written in English throughout. Half of it was
  in French, which the rest of the documentation is not.
- README links the architecture overview and the contributing, security and
  conduct documents. They shipped in 4.1.3 with nothing pointing at them.

---

## [4.1.3]

Stabilization and release hygiene. No new routing strategy — the suite was
already complete as of 4.1.1.

### Fixed

- **`WeightedStrategy` no longer resurrects a driver you weighted to zero.** A
  weight of `0` was clamped to `1`, so the one way to say "never send traffic
  here, but keep the driver configured" silently sent traffic there. Zero-weight
  drivers are now excluded from the draw; if every available driver is weighted
  zero, the first available one is returned rather than dividing by a zero total.

### Added

- `docs/architecture.md`: the request lifecycle from availability filtering
  through hard constraints, ranking, and the resilience decorators.
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`.
- `tests/Routing/InvariantPropertiesTest.php`: properties every strategy must
  hold, checked against all of them rather than one at a time.
- `tests/Routing/RoutingPerformanceBenchmarkTest.php`: a regression benchmark
  over 1000 selections across 20 drivers per strategy, with a deliberately loose
  CI-safe upper bound. It guards against a routing path becoming accidentally
  expensive; it is not a proof of algorithmic complexity.

### Changed

- `composer.json` declares `config.allow-plugins` for `php-http/discovery`, so a
  fresh install does not stop on an interactive plugin prompt.

---

## [4.1.2]

### Changed

- CHANGELOG only. This tag contains no code, test or documentation change of any
  kind — it exists because the 4.1.1 entry was written after 4.1.1 was tagged.

---

## [4.1.1]

### Added

- **`CapabilityStrategy`** (`Routing\CapabilityStrategy`): Hard constraint filtering candidates based on required technical features (`tools`, `vision`, `reasoning`, `streaming`).
- **`ReliabilityStrategy` & `ReliabilityTrackerInterface`** (`Routing\ReliabilityStrategy`, `Contract\Routing\ReliabilityTrackerInterface`): Ranking strategy favoring deployments with the highest observed success rate with configurable sample windows. Includes `InMemoryReliabilityTracker`.
- **`QuotaStrategy` & `QuotaTrackerInterface`** (`Routing\QuotaStrategy`, `Contract\Routing\QuotaTrackerInterface`): Hard constraint and ranking strategy protecting against exhausted or low quota limits. Includes `InMemoryQuotaTracker`.
- **`CompositeStrategy`** (`Routing\CompositeStrategy`): Enables pipeline composition of hard constraints (Capability, ContextWindow, Quota) followed by ranking strategies.
- **`RoutingStrategyFactory`** extended to instantiate capability, reliability, and quota strategies from options.

---

## [4.1.0]

### Added

- **Pluggable Load Balancing & Routing Strategies**:
  - `WeightedStrategy` (`Routing\WeightedStrategy`): Probabilistic weighted distribution (e.g. 70%/20%/10%).
  - `RandomStrategy` (`Routing\RandomStrategy`): Uniform random selection with injectable seed.
  - `LeastBusyStrategy` (`Routing\LeastBusyStrategy`): Active concurrency-aware routing via `ActiveRequestsTrackerInterface`.
  - `LatencyStrategy` (`Routing\LatencyStrategy`): Rolling latency-based routing (EMA) via `LatencyTrackerInterface`.
  - `CostStrategy` (`Routing\CostStrategy`): Minimum estimated USD cost routing via `$driver->estimateCost()`.
  - `UsageStrategy` (`Routing\UsageStrategy`): Accumulated token/request usage-based routing via `UsageTrackerInterface`.
  - `ContextWindowStrategy` (`Routing\ContextWindowStrategy`): Prompt size vs context capacity filtering.
- **`RoutingStrategyFactoryInterface` & `RoutingStrategyFactory`**: Dynamic instantiation of routing strategies from configuration strings.
- **`Contract\Routing` abstractions**: `RandomizerInterface`, `ActiveRequestsTrackerInterface`, `LatencyTrackerInterface`, `UsageTrackerInterface` with in-memory defaults.

---

## [4.0.0]

### Changed — breaking

- **The root namespace is now `CleatSquad\LlmRouter\`** instead of `LlmRouter\`.
  The package ships as `cleatsquad/php-llm-router`, but its namespace claimed
  `LlmRouter\`, a root name no vendor owns: it collided with any other library
  of that name, and `use LlmRouter\...` gave a reader no way to tell which
  package to install. PSR-4 expects `Vendor\Package\`.

  Nothing else moved — no class renamed, split or removed, no signature,
  constructor order or default changed, no dependency touched, PHP floor still
  8.2. Migration is a search-and-replace over your own imports; see
  [UPGRADE.md](UPGRADE.md#3x--400) for a re-runnable one-liner covering the
  escaped form found in JSON/NEON/baselines too.

  Shared state is unaffected: Redis keys (`llm_router:*`), cache entries,
  breaker and quota payloads keep their format, so a mixed 3.x/4.0 rollout is
  safe.

---

## [3.4.0] — 2026-08-14

### Changed

- Published under the `cleatsquad` vendor: `mohaelmrabet/php-llm-router` became
  **`cleatsquad/php-llm-router`**. A Composer name change only — the namespace
  stayed `LlmRouter\` in this release and no `use` statement needed to change.

---

## [3.3.0] — 2026-08-11

### Changed

- The drift checker no longer reports **moving aliases** (`gemini-flash-latest`,
  `gemini-flash-lite-latest`, `gemini-pro-latest`) as missing models. An alias
  resolves to whichever version Google currently points it at, so it carries no
  rate of its own — cataloguing one would mean quoting a price that silently
  becomes wrong the day the alias moves, which is precisely the failure this
  work exists to prevent.
- Also filtered from the report: Gemini previews, robotics, music (`lyria`),
  deep-research and omni endpoints, and Google's open-weight `gemma-*` models,
  which are served free-tier with no paid per-token rate. Gemini's section of
  the report drops from 16 entries to none.

### Correction to the 2.3.0 notes

2.3.0 listed `gemini-flash-lite-latest` among "retired catalogue entries". That
was wrong: the alias is still served — it is simply absent from the pricing
page, because aliases have no rate of their own. Moving the default to the
explicit `gemini-2.5-flash-lite` was still the right call, since a versioned
model has a stable price, but it was not a retirement.

---

## [3.2.0] — 2026-08-11

### Fixed

- **`open-mistral-nemo` removed.** Kept in 2.3.0 out of caution when it vanished
  from Mistral's pricing page; the live `/v1/models` response confirms it is no
  longer served, so it was a selectable entry that could only fail.

### Added

- `ministral-3b-latest` ($0.10/$0.10 per million tokens) and
  `ministral-14b-latest` ($0.20/$0.20), from Mistral's published rates.
  `ministral-8b-latest` was already correct.
- The drift checker now matches Mistral's alias/snapshot naming
  (`mistral-medium-latest` ↔ `mistral-medium-2505`) and filters its voice,
  OCR, moderation and `labs-` experimental models, which are not chat models.

### Known limit — reasoning on Mistral

`MistralDriver` sends `prompt_mode: "reasoning"`, which is the Magistral
family's switch. **No Magistral model is in the catalogue**, because Mistral
publishes no per-token rate for it and its own model overview lists Magistral
and Devstral among deprecated models. A reasoning request through this driver
therefore needs a Magistral model registered via `$extraModelPricing` with a
rate you have confirmed. The same applies to Devstral and `mistral-code`.

---

## [3.1.0] — 2026-08-11

### Fixed

- **Groq's two reasoning dialects were conflated.** Groq serves both, and they
  are not interchangeable: Qwen takes `reasoning_effort: none|default` and
  accepts `reasoning_format`, while GPT-OSS takes the graded `low|medium|high`
  and **rejects `reasoning_format` outright**. 2.1.0 sent Qwen's spelling to
  every model, so any reasoning request against `openai/gpt-oss-*` carried two
  invalid parameters. Each catalogue entry now records the dialect it speaks.
- **Groq's Llama models are marked non-reasoning.** Groq documents no reasoning
  parameters for `llama-3.3-70b-versatile` or `llama-3.1-8b-instant`, and the
  latter is the driver's default — so an unqualified reasoning request sent
  parameters the model does not accept. It now raises
  `UnsupportedReasoningException` naming the models that do reason.

### Added

- `qwen/qwen3.6-27b` to the Groq catalogue ($0.60/$3.00 per million tokens),
  the reasoning model that endpoint serves.
- The drift checker declines `groq/compound*` and `allam-2-7b`: Groq publishes
  no per-token rate for them — the compound entries are tool-orchestrating
  systems rather than token-billed models — so there is nothing to put in a
  pricing table.
- Kimi is now watched by the drift checker; it was absent because the script
  predated its catalogue.

---

## [3.0.0] — 2026-08-11

### Breaking

- **`KimiDriver` now has a model catalogue, and refuses models outside it.** It
  used to forward any name it was given and price it from a hardcoded guess
  (`0.0016`/`0.0033`/`0.0083` per 1k, keyed off "32k"/"128k" in the name). It
  now behaves like every other priced driver: `kimi-k3`, `kimi-k2.7-code`,
  `kimi-k2.6` and `kimi-k2.5`, priced in USD from Moonshot's published rates for
  the **international endpoint** (`api.moonshot.ai`), and `UnknownModelException`
  for anything else.

  **The mainland endpoint bills in yuan.** The `moonshot-v1-*` family served by
  `api.moonshot.cn` is not in the catalogue: its published rates are in ¥, and
  putting them in a table whose values feed `estimatedCostUsd` would report a
  cost roughly seven times too low. If you use that endpoint, register those
  models through `$extraModelPricing` with rates you have converted yourself.

  The constructor's `$moonshotModel` default changed from `'moonshot-v1-8k'` to
  `null`, which resolves to `kimi-k2.6`.

### Added

- **The OpenAI catalogue covers the current families.** It held two models,
  neither of which can reason — so no reasoning request could succeed without
  registering a model by hand. Added the GPT-5 family (`gpt-5.6-sol`,
  `gpt-5.6-terra`, `gpt-5.6-luna`, `gpt-5.5`, `gpt-5.4`, `gpt-5`, `gpt-5-mini`,
  `gpt-5-nano`) and the o-series (`o1`, `o1-pro`, `o3`, `o3-pro`, `o3-mini`),
  priced from OpenAI's published rates. `gpt-4o` and `gpt-4o-mini` were verified
  correct and keep their `reasoning => false` flag.

### Fixed

- **The drift checker raised two kinds of false alarm**, both found on its first
  real run:
  - *Aliases.* `/v1/models` lists dated snapshots (`claude-haiku-4-5-20251001`)
    while catalogues store the stable alias (`claude-haiku-4-5`). The alias was
    reported as retired; acting on that would have removed a working model. An
    entry now counts as served if any listed ID starts with it.
  - *Non-chat models.* Provider listings include embeddings, speech, moderation
    and realtime endpoints. OpenAI alone contributed 122 lines of noise, burying
    the real findings. Only chat-shaped IDs are reported now.

  The retired-entry section also notes that a model restricted to your account
  tier — a preview or invite-only model such as `claude-mythos-5` — appears the
  same way and should be checked before removal.
- `gemma2-9b-it` removed from the Groq catalogue: confirmed gone both from
  Groq's production model list and from the live `/models` response.

---

## [2.3.0] — 2026-08-11

### Fixed

- **Two drivers defaulted to a model that no longer exists.** `DeepSeekDriver`
  defaulted to `deepseek-chat` and `GeminiDriver` to `gemini-flash-lite-latest`;
  both were retired by their providers (DeepSeek's aliases on 2026-07-24). Every
  call that named no model was failing at the provider. Defaults are now
  `deepseek-v4-flash` and `gemini-2.5-flash-lite`.
- **Mistral Large was priced at four times its real rate.** The table claimed
  $2/$6 per million tokens; Mistral Large 3 is $0.5/$1.5. `mistral-small-latest`
  input was also overstated ($0.2 vs $0.15). Cost estimates for those models
  were wrong by a wide margin, reported with full confidence.
- **A model ID containing a slash was truncated.** `resolveModel()` treated
  everything before a `/` as a provider prefix, so Groq's `openai/gpt-oss-120b`
  became `gpt-oss-120b` and raised `UnknownModelException`. The full name is now
  tried first, and the prefix stripped only if that fails — so
  `anthropic/claude-sonnet-5` still resolves.
- Retired catalogue entries removed: `deepseek-chat`, `deepseek-reasoner`,
  `gemini-2.0-flash` (shut down 2026-06-01), `gemini-1.5-flash`,
  `gemini-flash-lite-latest`.

### Added

- Current models across four providers, priced from each provider's published
  per-million rates: Gemini 3.6/3.5/3.1 and 2.5 Flash-Lite; `deepseek-v4-flash`
  and `deepseek-v4-pro`; `mistral-medium-latest` and `ministral-8b-latest`;
  Groq's `openai/gpt-oss-120b` and `openai/gpt-oss-20b`.
- **`bin/check-model-drift.php` and a weekly `model-drift` workflow.** Compares
  each driver's catalogue against the provider's own `/models` endpoint and
  opens (or updates, or closes) a single issue describing the drift. It reports
  and never edits: model existence is checkable, pricing is not exposed by any
  provider's API, and a scraped rate that turned out wrong would make
  `estimateCost()` confidently incorrect — worse than a stale figure.

### Note on verification

Groq's `llama-3.3-70b-versatile` and `llama-3.1-8b-instant`, Gemini's
`gemini-2.5-pro` and `gemini-2.5-flash`, Mistral's `codestral-latest`, and all
Anthropic rates were checked against the providers' published pricing and were
already correct. `gemma2-9b-it` and `open-mistral-nemo` no longer appear on
their providers' pages but were kept rather than removed, since callers may
still name them; their last published rates stand.

---

## [2.2.0] — 2026-08-11

### Added

- **The current Anthropic models are in the catalogue.** `ClaudeDriver` knew
  only `claude-opus-4-8`, `claude-sonnet-5` and `claude-haiku-4-5`, so since
  2.0.0 asking for `claude-opus-5` — Anthropic's recommended model — raised
  `UnknownModelException`. Added `claude-opus-5`, `claude-fable-5`,
  `claude-mythos-5`, `claude-opus-4-7`, `claude-opus-4-6` and
  `claude-sonnet-4-6`, priced from the published per-million rates. The three
  existing entries were already correct and are unchanged.
- **Per-model capability flags.** A pricing entry may now carry
  `reasoning => false` (the model cannot reason) or `thinkingAlwaysOn => true`
  (its thinking cannot be switched off) beside its rates. Absent flags keep the
  driver's default, so existing `$extraModelPricing` entries are unaffected.
- `Exception\UnsupportedReasoningException`, a `RuntimeException` so
  `FailoverDriver` can move to a driver whose model does reason.

### Fixed

- **Reasoning on OpenAI was a guaranteed 400.** 2.1.0 sent `reasoning_effort`
  to whatever model was resolved, and both models in the shipped catalogue —
  `gpt-4o` and `gpt-4o-mini` — reject that parameter outright. They are now
  marked `reasoning => false`, and a reasoning request against them fails with
  a message naming the model and pointing at `$extraModelPricing` instead of an
  opaque provider error. Two tests added in 2.1.0 were themselves building
  requests the API would have rejected.
- **Asking Claude Fable 5 not to think was a 400.** Thinking is always on for
  `claude-fable-5` and `claude-mythos-5`; `thinking: {type: "disabled"}` is
  rejected there. `ReasoningEffort::None` now omits the thinking block for
  those models rather than sending an instruction they refuse.

---

## [2.1.0] — 2026-08-11

### Added

- **Reasoning support across all eight LLM drivers.** `supportsReasoning()`
  previously returned true for Claude and DeepSeek while nothing was sent and
  nothing was read: Claude's `thinking` blocks were dropped by the response
  parser and DeepSeek's `reasoning_content` was never looked at, so callers
  paid for thinking tokens and saw none of it.

  `LLMRequest` gains `$reasoningEffort` (a neutral `ReasoningEffort` enum),
  `$includeReasoning`, and `$onReasoning` for streaming. `LLMResponse` gains
  `$reasoning`, `$reasoningTokens`, `$reasoningSignature`, `hasReasoning()`
  and `toMessage()`.

  Effort — not a token budget — is the portable abstraction: OpenAI, DeepSeek
  and Groq all spell it `reasoning_effort` and Anthropic `output_config.effort`,
  whereas `thinking.budget_tokens` is deprecated on Claude 4.6 and returns a
  400 on Claude 4.7 and later.

  Omitting `$reasoningEffort` sends nothing, so existing requests are
  byte-identical to before.

- **`LLMResponse::toMessage()`** builds the assistant history entry, carrying
  the reasoning trace so drivers can replay it. Anthropic, Mistral and Moonshot
  all require this on the following turn; Moonshot documents that dropping it
  during a tool-calling loop degrades the model.

- Reasoning while streaming reaches the caller through `$onReasoning`, never
  through the yielded values, so existing `foreach` loops keep receiving only
  the visible answer.

### Fixed

- **Gemini spliced its reasoning into the answer.** A Gemini thought is an
  ordinary text part flagged `thought: true`, and `extractParts()` concatenated
  every text part — so enabling thinking would have put the model's scratch
  work in `$content`.
- **Claude returned streamed tool calls in arrival order.** Same defect fixed
  in `ParsesChatCompletionSse` in 1.14.0, still present in `ClaudeDriver`:
  `array_values()` over an index-keyed accumulator preserves insertion order,
  so a block opened out of sequence paired each id with another call's
  arguments.
- `CachingDriver`'s key now includes the reasoning settings; without it a
  reasoned answer could be served to a request that asked for no reasoning.

### Changed

- `supportsReasoning()` now returns true on all eight drivers. It describes
  what the driver can express, not what your chosen model accepts — the four
  tests that pinned it to false were updated, with that distinction recorded.
- PHPStan baseline reduced from 12 entries to 6: widening the `$messages` type
  made four of them obsolete, and two more resolved with it.

---

## [2.0.0] — 2026-08-11

Single-change major: the only breaking item is model resolution. Everything
else in 1.14.1 is unchanged, so upgrading is a `composer require` plus the
check described in UPGRADE.md.

### Breaking

- **An explicitly requested unknown model is now refused instead of silently
  replaced.** Six drivers (Claude, OpenAI, Gemini, DeepSeek, Groq, Mistral)
  ended `resolveModel()` with `isset(self::PRICING[$model]) ? $model : <default>`.
  Asking `OpenAiDriver` for `gpt-5` returned an answer from `gpt-4o-mini`,
  priced as `gpt-4o-mini`, with nothing in the response or the cost estimate
  revealing the substitution. It now throws `Exception\UnknownModelException`.

  This is a real break: the shipped pricing tables hold only 2–5 models each,
  so any model they predate — `gpt-4-turbo`, `claude-3-5-sonnet`, anything
  released since — used to "work" and now raises. See UPGRADE.md for the
  one-line fix (`$extraModelPricing`).

  Passing no model at all is unchanged: that is a caller declining to choose,
  not a caller being overruled, and still resolves to the driver's default.

  `UnknownModelException` extends `RuntimeException` on purpose, so
  `FailoverDriver` treats it as a failure worth failing over from — in a mixed
  chain the driver that doesn't know a model steps aside for the one that does.

  `KimiDriver` (no pricing table, forwards the name as given) and
  `OllamaDriver` (resolves against the models actually installed locally, tuned
  by `ad6c8f5`) are deliberately unchanged.

### Added

- `$extraModelPricing` constructor argument on the six priced drivers: register
  a model this release predates, or correct a stale price, without waiting for
  a new version of the package. Entries also show up in `getModels()`.
- `Driver\Concern\ResolvesPricedModel`, replacing six copies of the same
  resolution code.

---

## [1.14.1] — 2026-08-11

### Fixed

- **CI actually runs the shared-store tests.** `tests/Fixtures/FakeRedis` only
  declares itself when class `Redis` is absent, on the stated assumption that
  CI has no `ext-redis`. The GitHub runners do ship it, so every `Redis*Store`
  test ran against a real phpredis client with no server behind it and errored
  — the workflow had been red since before the store rework, not because of it.
  `setup-php` now uninstalls the extension for the test matrix, so the stub
  takes effect as intended.

No source changes: `src/` is byte-identical to 1.14.0.

---

## [1.14.0] — 2026-08-11

### Security

- **`RedisCacheStore`, `RedisCircuitBreakerStore` and `RedisRateLimitStore` no
  longer call `unserialize()`.** Values are stored as JSON (or, for the quota,
  as plain integers) and rebuilt field by field into the DTO. The previous code
  ran `@unserialize($raw)` and only checked the resulting type afterwards — by
  which point `__wakeup()`/`__destruct()` of whatever class the payload named
  had already run. Anyone able to write to the Redis instance (a shared or
  misconfigured server, a compromised neighbour, a corrupted key) had a
  deserialization gadget primitive. A type check after the fact cannot close
  this; only never deserializing can.

### Fixed

- **Quota counting is atomic on shared stores.** `RateLimitedDriver` used
  `getWindow()` then `saveWindow()`, a read-modify-write: two workers reading
  the same window before either wrote both stored `count + 1`, losing an
  increment and admitting more traffic than the ceiling allowed.
  `RedisRateLimitStore` now counts with `HINCRBY` on a per-window hash and
  reserves slots through the new `AtomicRateLimitStoreInterface`.
  Process-local stores keep the original path, where the race cannot occur.
- **Fragmented tool calls arriving out of order are no longer swapped.**
  `ParsesChatCompletionSse` accumulated by index correctly but flattened with
  `array_values()`, which preserves *insertion* order. A provider opening tool
  call #1 before #0 handed the caller the calls in the wrong positions,
  pairing each `id` with another call's arguments. The accumulator is now
  sorted by index before being returned.
- **`Retry-After` values that are neither an integer nor a date no longer read
  as "retry immediately".** `RetryAfterParser` passed anything non-numeric to
  `DateTimeImmutable`, which happily reads `"1.5"` or `"+30"` as an offset from
  now and collapses them to a delay of 0 — the worst possible reading of a
  header whose purpose is to make the caller wait. Unparseable values now
  return `null`, meaning "no usable value, use your own backoff".
- **`RetryingDriver` honours the provider's `Retry-After`.** It computed an
  exponential backoff and ignored `RateLimitException::getRetryAfterSeconds()`
  entirely, so a 429 asking for 30 seconds was retried after ~0.5s and earned
  another 429. The provider's delay now replaces the computed one, still capped
  by `maxDelaySeconds`. A `RateLimitException` is also retryable on its own
  type, without needing a wrapped Guzzle exception.
- **`CircuitBreakerDriver` applies the `Retry-After` cooldown it already
  accepted.** `CircuitBreakerState::withFailure()` took a `$retryAfterSeconds`
  argument that the driver never passed, so a 429 asking for an hour reopened
  the circuit after the configured `$openSeconds`. This was covered by a test
  that shipped red in v1.13.1.
- **A shared store outage no longer breaks LLM calls.** The Redis stores let
  every connection error propagate into the call path. Cache and circuit
  breaker now fail open (degrade to no cache / closed breaker) and log at
  warning level; the quota deliberately keeps failing closed.

### Added

- `Cache\Psr16CacheStore` and `CircuitBreaker\Psr16CircuitBreakerStore` — share
  cache and breaker state over any PSR-16 backend (filesystem, APCu, Memcached,
  PDO, ...) for deployments without Redis, which previously fell back to a
  process-local store without saying so.
- `RateLimit\ApcuRateLimitStore` — atomic per-machine quota via `apcu_inc()`,
  the Redis-free option that can honestly claim atomicity. PSR-16 has no atomic
  increment, so no PSR-16 quota store is shipped.
- `RateLimit\AtomicRateLimitStoreInterface` — opt-in extension implemented by
  backends with a real atomic increment. `RateLimitedDriver` detects it and
  takes the atomic path; stores implementing only the base interface are
  unaffected.
- `Serialization\LLMResponseCodec` and `Serialization\CircuitBreakerStateCodec`
  — the JSON encoding shared by the Redis and PSR-16 stores.
- Optional PSR-3 logger on every shared store, so a fail-open degradation is
  recorded rather than silent.
- Optional `$sleeper` callable on `RetryingDriver`, so the retry schedule is
  assertable without waiting it out (and so an event-loop application can yield
  instead of blocking a worker).
- 93 tests: deserialization/gadget payloads, store outage and recovery, quota
  concurrency and exact-limit behaviour, out-of-order and truncated tool-call
  streams, `Retry-After` edge cases, weighted-rotation degenerate weights, and
  structured fail-over exhaustion.

### Changed

- `composer.json` now requires `psr/simple-cache` ^3.0 (interfaces only, no
  transitive dependencies) and suggests `ext-apcu` and a PSR-16 implementation.
- CI runs the security-tagged tests as their own step (`composer test:security`).
- Documentation: removed `LiteLLMDriver`, which was deleted in `ee52c35` but
  still documented; documented `FailoverDriver`, which had never been; added
  "Sharing state across processes", "Failure semantics", a dependency table and
  a known-limits section.

---

## [1.13.1] — reconstructed

### Fixed

- Re-release of 1.13.0. Reason not documented — the two tags point at commits
  with identical messages (`35867a5`, `b31404d`).

## [1.13.0] — reconstructed

### Added

- Generic `Retry-After` and rate-limit handling across drivers:
  `Exception\RateLimitException` carrying a typed retry delay,
  `Http\RetryAfterParser`, and the `Driver\Concern\HandlesHttpRateLimit` trait
  used by the HTTP drivers.
- `CircuitBreakerState::withFailure()` gained a `$retryAfterSeconds` parameter
  so a provider-supplied cooldown could override `$openSeconds`.

### Known issue

- `CircuitBreakerDriver` never passed that parameter, so the feature had no
  effect. Shipped with a failing test. Fixed in 1.14.0.

## [1.12.2] — reconstructed

### Fixed

- Handle an array-shaped `choice` content and cast it to string in the drivers,
  for providers that return content blocks where a string was expected.

## [1.12.1] — reconstructed

- Tagged on the same commit as 1.12.0. Reason not documented.

## [1.12.0] — reconstructed

### Changed

- Retry backoff uses decorrelated jitter (50–100% of the exponential delay), so
  workers retrying the same outage don't resynchronise onto one schedule.

### Fixed

- `OllamaDriver` never picks an embedding model as a chat fallback.

---

## Earlier history — reconstructed, untagged

Grouped by theme; each bullet corresponds to one or more commits.

### Added

- **Initial extraction** (`6862d99`) — provider-agnostic LLM router covering
  Claude, OpenAI, Ollama, LiteLLM and Kimi, extracted from a production
  chat/agent platform.
- **Real streaming for all drivers** (`55c9faf`) — SSE and NDJSON parsing per
  provider wire format.
- **Streamed tool-call accumulation** (`a9fec57`) — fragments accumulated by
  index and returned via `Generator::getReturn()`.
- **`CircuitBreakerDriver`** (`41ef8d7`) — fail fast after N consecutive
  failures instead of every caller rediscovering the same outage.
- **Decorators and four more providers** (`da7e910`) — caching, retrying and
  rate-limiting decorators, plus Gemini, Mistral, Groq and DeepSeek.
- **MCP and A2A client drivers** (`5dd429d`) — `McpClientDriver` (via
  `mcp/sdk`) and `A2AClientDriver` (JSON-RPC 2.0 over HTTP).
- **Embedding drivers** (`3486703`, `3f4ba3d`) — OpenAI, Gemini, Mistral,
  Ollama, plus `FallbackEmbeddingDriver`.
- **Audio transcription drivers** (`95ef09d`, `816c11b`, `0eaa1c4`) — OpenAI
  and Groq, `FallbackAudioDriver`, and exposure of Whisper confidence signals
  for low-quality detection.
- **Redis-backed stores** (`472d326`) — shared cache, circuit-breaker and
  rate-limit state across processes.
- **`FailoverDriver`** (`2005eea`) — real cross-provider fail-over: exclude the
  failed driver, ask the strategy for the next candidate, and stop failing over
  once a stream has emitted its first fragment.
- **CI** (`6a5f595`, `aafc673`) — GitHub Actions across PHP 8.2/8.3/8.4,
  PHPStan level 8, and `composer audit`.
- **Injectable Guzzle client** (`397dd9a`) — `HttpClient` accepts an existing
  client instead of always building its own.

### Changed

- Namespace renamed `Concio\LlmRouter` → `LlmRouter` (`b0f520b`) and package
  renamed to `mohaelmrabet/php-llm-router` (`0f1d1a5`), for community
  neutrality.
- `ParsesOpenAiCompatibleSse` renamed to `ParsesChatCompletionSse` (`bea6925`)
  — named after the wire format rather than the vendor.
- phpDoc blocks simplified across `src/` (`33d9de6`).

### Fixed

- Kimi: stop silently discarding an explicit model request (`b03fd60`); add a
  `moonshotModel` constructor parameter (`6219080`); force temperature to 1 for
  K2 reasoning models (`d8c255a`).
- Gemini: stop defaulting to `gemini-2.0-flash`, which is dead on the free tier
  (`eb3f666`).
- Vision: translate OpenAI-shaped image content for Claude and Gemini
  (`dde0d65`).
- MCP: always disconnect the transport even when `connect()` throws
  (`a1da806`).
- All drivers: add `read_timeout` to streaming Guzzle requests (`609f355`), so
  a stalled stream cannot hang a worker indefinitely.

### Breaking

- **`LiteLLMDriver` removed** (`ee52c35`) — unused in this package and in the
  application it was extracted from. A LiteLLM proxy is OpenAI-compatible, so
  `OpenAiDriver` pointed at the proxy URL replaces it. The README continued to
  document the removed driver until [1.14.0](#1140--2026-08-11).
- **Namespace rename** `Concio\LlmRouter` → `LlmRouter` (`b0f520b`), before the
  package had external consumers.
