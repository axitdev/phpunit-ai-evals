# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- System One models (TypeSafe Jev): calls to `/v1/systemone` are recorded, replayed and traced; their typed answers are `AiInteraction::$decisions`.
- Decision assertions: `assertDecision()`, `assertNoulAtLeast()`, `assertNoulAtMost()`, `assertScoreBetween()`, `assertConfidenceAtLeast()`, plus `getDecision()`.
- `assertBrierScoreAtMost()` to check that predicted probabilities are calibrated over a dataset.
- A System One judge (`AI_EVAL_JUDGE_PROVIDER=system-one` or `#[AiJudgeConfig(provider: 'system-one')]`): one yes/no question per rubric criterion or per sentence of the output, scored by the weakest answer.
- `assertMatchesRubric()` takes a list of criteria that must all hold.

### Changed
- **Breaking:** `JudgeClientInterface::judge()` takes a `JudgeRequest` instead of two prompts, and judges implement `cachePayload()`. Prompt building moved into the LLM judge.
- Judges set with `useJudgeClient()` are now cached in the cassette like the built-in ones; `useJudgeClient($judge, cache: false)` restores the old behaviour.
- Verdicts are hashed on every key of the judge's cache payload. The built-in LLM judge sends the same payload as before, so existing cassettes still replay.
- The LLM judge no longer needs a lazy proxy: PSR-17 factories are looked up on the first live request.
- `JudgePrompts::rubric()` takes a list of criteria, and the test-only `Psr18JudgeClient::retryDelay()` is gone (both were internal).

## [0.0.2] - 2026-10-24

First pre-release. Experimental: the API, the cassette format and request matching may change in any release before 1.0.

0.0.1 was withdrawn the day it was published: its tag was moved afterwards, which Packagist rightly refuses. 0.0.2 has the same code.

### Added
- Smart VCR: a PSR-18 decorator that records and replays LLM calls by semantic hash, with `auto`, `replay`, `record` and `live` modes.
- Semantic hashing that ignores key order and sampling settings but keeps reasoning and structured-output settings; dates, UUIDs and base64 blobs become placeholders (`#[AiVcr(ignoreDatesAndUuids: false)]` keeps dates and UUIDs for prompts about them), plus custom `ignorePatterns`.
- Providers: OpenAI Chat Completions and compatible APIs (Azure OpenAI included), OpenAI Responses, Anthropic (also on Vertex AI), Gemini, Ollama and Amazon Bedrock Converse, streaming included (SSE, NDJSON, Gemini JSON arrays, AWS event streams).
- Other traffic is recorded as `raw_http`, hashed after the same placeholder replacements; binary bodies are kept as base64. Rate limits, timeouts, rejected credentials (401, 403, 407), server errors, errors reported inside a stream and dropped connections are never recorded, and the retry that succeeds replays as the first attempt.
- Custom provider parsers by overriding `aiPayloadParsers()`; each parser adds the request details its provider keeps in the URL, and `StreamingPayloadParserInterface` lets it merge and replay its own streams.
- Versioned cassettes: a cassette of another major format version is refused instead of read wrongly.
- Trace assertions: `assertToolCalled()`, `assertToolNotCalled()`, `assertToolCalledTimes()`, `assertToolCallOrder()`, `assertTokenUsageLessThan()`, `assertLatencyLessThan()`.
- `assertMatchesJsonSchema()` for structured outputs (with `opis/json-schema`).
- LLM-as-a-Judge assertions with cached verdicts: `assertNoHallucination()`, `assertMatchesRubric()`; any OpenAI-compatible judge (OpenAI, Claude, Gemini, Ollama), with optional JSON mode and temperature, and retries on rate limits. A judge answer without a score between 0 and 1 is an error, never a saved verdict.
- `assertSampled()` for pass@k runs, `assertPassRate()` and `Dataset` (JSON, JSONL, CSV) for dataset evals; malformed cases are rejected rather than dropped.
- JSON eval report (`reportPath`) and baseline comparison (`baselinePath`, `baselineTolerance`).
- PHPUnit extension with a run summary and `AI_EVAL_PRUNE=1` to remove unused cassette entries.
- Secret redaction in cassettes: auth, session and cookie headers, account identifiers, URI credentials, and credentials in `raw_http` bodies, by name and by credential words in the name; extendable with the `redactHeaders` and `redactKeys` extension parameters. Cassette lock files live in a per-user private temp folder.
- Integration guides for Guzzle, Symfony HttpClient, openai-php/client, the Anthropic PHP SDK, Laravel `Http` and Prism.

[Unreleased]: https://github.com/axitdev/phpunit-ai-evals/compare/v0.0.2...HEAD
[0.0.2]: https://github.com/axitdev/phpunit-ai-evals/releases/tag/v0.0.2
