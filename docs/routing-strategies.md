# Routing Strategies in `cleatsquad/php-llm-router`

## Overview

`cleatsquad/php-llm-router` provides a native PHP LLM routing library inspired by proven multi-deployment routing patterns.

It cleanly separates **Routing** (deciding *who* to call before sending a request) from **Failure Handling** (retries, failovers, circuit breakers, rate limiting).

---

## Architecture & Responsibilities

```text
                 Router / FailoverDriver
                           │
                 RoutingStrategyInterface
                           │
             ┌─────────────┼─────────────┐
             ▼             ▼             ▼
       Priority     Weighted / Random   Metrics-driven
       Strategy       Strategies       (LeastBusy, Latency, Cost)
                           │
                           ▼
                    Selected Driver
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
* **LiteLLM**: Router centralise à la fois le load-balancing, le failover, les retries, le budget-tracking, la gestion d'API keys et de proxy HTTP Python (Async).
* **php-llm-router**: Modèle par **Décorateurs & Composabilité native PHP**. Les stratégies de routage sont des objets légers implémentant `RoutingStrategyInterface`. Le failover est un découpé dans `FailoverDriver`, le circuit breaker dans `CircuitBreakerDriver`, le rate limit dans `RateLimitedDriver`, etc.

### 2. Strategy Parity & Differences
* **Priority Strategy**: Inspiré de la priorité LiteLLM. `php-llm-router` supporte aussi la bascule dynamique via `$request->preferQuality` (`qualityPriorities`).
* **Weighted & Random Strategies**: Permettent un load balancing probabiliste. Dans `php-llm-router`, la source d'aléatoire est abstraite via `RandomizerInterface`, garantissant un comportement 100% déterministe dans les tests unitaires.
* **LeastBusy Strategy**: Similaire à LiteLLM `least-busy`, mais utilise l'interface `ActiveRequestsTrackerInterface` au lieu de dépendre directement d'un Redis global obligatoire.
* **Latency Strategy**: Calculé via `LatencyTrackerInterface` (Moving Average). Prévoir la phase de warm-up avec un `defaultLatencyMs` configurable.
* **Cost Strategy**: Déterminé avant l'appel via `$driver->estimateCost($request)`.

### 3. Intentional Omissions & Why
* **Global Proxy State / Central Daemon**: LiteLLM tourne en tant que serveur proxy autonome Python. `php-llm-router` est une bibliothèque native PHP intégrable directement sans latence réseau supplémentaire ni daemon secondaire.
* **Hardcoded Pricing Databases**: Les prix ne sont pas codés en dur dans la stratégie de coût mais dynamiquement résolus via le modèle/catalogue de chaque driver (`estimateCost()`).

---

## Conclusion

`php-llm-router` offre ainsi une suite complète et extensible de stratégies de routing LLM pour PHP, respectant les normes de typage strict PHP 8.2+ et de testabilité sans dépendance globale.
