# concio/llm-router

Provider-agnostic LLM client for PHP. One interface, five drivers (Claude, OpenAI,
Ollama, LiteLLM, Kimi/Moonshot), and a pluggable routing strategy for
priority/fallback selection — the PHP equivalent of what LiteLLM does for Python.

Extracted from a production chat/agent platform where it routes every LLM call
across local (Ollama) and cloud (Claude, OpenAI, Kimi, LiteLLM-proxied) models,
failing over automatically when a provider is down, rate-limited, or out of
credit.

## Install

```bash
composer require concio/llm-router
```

## Usage

```php
use Concio\LlmRouter\Driver\ClaudeDriver;
use Concio\LlmRouter\Driver\OllamaDriver;
use Concio\LlmRouter\DTO\LLMRequest;
use Concio\LlmRouter\Http\HttpClient;
use Concio\LlmRouter\Routing\PriorityStrategy;

$http = new HttpClient();

$drivers = [
    new OllamaDriver($http, ollamaUrl: 'http://localhost:11434', ollamaModel: 'llama3'),
    new ClaudeDriver($http, anthropicApiKey: getenv('ANTHROPIC_API_KEY') ?: ''),
];

// Higher number = tried first, as long as isAvailable() is true.
$strategy = new PriorityStrategy(priorities: ['ollama' => 10, 'claude' => 5]);

$request = new LLMRequest(messages: [
    ['role' => 'user', 'content' => 'Say hello in one word.'],
]);

$driver = $strategy->select($request, $drivers);
$response = $driver->chat($request);

echo $response->content; // "Hello"
echo $response->costUsd; // 0.0 for Ollama, real $ for Claude
```

### Fallback across two priority tiers

`LLMRequest::$preferQuality` lets one strategy instance serve two different
orderings — e.g. "fast/cheap for classifier calls" vs. "best quality for the
user-facing reply" — without instantiating two strategies:

```php
$strategy = new PriorityStrategy(
    priorities: ['ollama' => 25, 'litellm' => 10, 'claude' => 1],       // fast-first
    qualityPriorities: ['litellm' => 25, 'claude' => 15, 'ollama' => 1] // quality-first
);

$classifierDriver = $strategy->select(new LLMRequest(messages: $msgs), $drivers);
$replyDriver = $strategy->select(new LLMRequest(messages: $msgs, preferQuality: true), $drivers);
```

## Drivers included

| Driver | Provider | Notes |
|---|---|---|
| `ClaudeDriver` | Anthropic Messages API | tools, vision, extended reasoning |
| `OpenAiDriver` | OpenAI Chat Completions | tools, vision |
| `OllamaDriver` | Local Ollama | free, fuzzy-matches the closest locally-pulled model |
| `LiteLLMDriver` | A LiteLLM proxy | fronts whatever LiteLLM itself routes to |
| `KimiDriver` | Moonshot AI | tools |

Every driver implements `Concio\LlmRouter\Contract\Driver\LLMDriverInterface`:
`chat()`, `stream()` (not yet implemented by the bundled drivers — see below),
`getModels()`, `isAvailable()`, `healthCheck()`, `estimateCost()`, and
`supportsStreaming()/Tools()/Vision()/Reasoning()` capability flags.

Write your own driver for another provider by implementing the same
interface — nothing else in this package needs to know about it.

## What this package does *not* do

- **No streaming yet.** `stream()` exists on the interface but every bundled
  driver currently throws — implemented as a stub deliberately, to be filled
  in by SSE-parsing logic per provider without breaking the interface
  contract in a future release.
- **No circuit breaker / health-based auto-disable / DB-backed usage
  tracking.** `PriorityStrategy` only checks `isAvailable()` synchronously,
  per call — it has no memory across requests. If you need "stop trying a
  provider for N minutes after M consecutive failures" or persisted
  cost/usage accounting, build that as a decorator around a driver or
  strategy in your own app; this package intentionally stays framework- and
  storage-agnostic.
- **No prompt templating, no agent/tool-execution loop.** This is a thin,
  uniform transport layer over each provider's chat endpoint — orchestration
  belongs one layer up.

## Requirements

- PHP >= 8.2
- `guzzlehttp/guzzle` ^7.8

## License

MIT
