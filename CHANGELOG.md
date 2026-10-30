# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- PHP 8.3 support. PHP 8.3 runs with PHPUnit 12.5; PHPUnit 13 still needs PHP 8.4. CI tests PHP 8.3, 8.4 and 8.5, always in replay-only mode.
- Binary and multipart requests: images, audio and files sent as data URIs, base64 fields (Anthropic, Gemini `inlineData`, OpenAI `input_audio`), multipart uploads or raw binary bodies are matched and stored as `sha256`, `size` and `mime`, never as bytes. Works with Laravel `Http::attach()` and PSR-7 multipart streams.
- Configurable request matching: `ignoreFields`, `normalizeFields`, `matchHeaders` and `matchFields`, on `#[AiVcr]` per class or test and as extension parameters for the whole suite. Fields are dot paths with `*` wildcards.
- `CassetteMissException` names each field that differs from the closest recording, with both values, instead of a line diff.
- Structured-output assertions: `assertScoreAtLeast()`, `assertScoreBetween()` and `assertFieldIn()` on dot paths or JSON Pointers. They, and `assertMatchesJsonSchema()`, accept an `AiInteraction` or default to the last model answer, and explain invalid JSON (empty output, a Markdown fence, text around the JSON).
- `runConversation()` with `Turn::user()` for multi-turn evals: assertions after every turn with the full history, and each turn cassetted on its own so editing one turn re-records only that turn.
- `FakesAiHttp::fakeAiHttp()` for Laravel: replays through `Http::fake()`, and records by passing calls through to the provider. Replay mode also prevents stray requests.
- `assertSemanticallySimilar()`, `assertVariantsMatchRubric()` and `assertVariantsSemanticallySimilar()`. Judge prompts now grade meaning in any language, and variant failures list each failing variant with its score and reason.
- Credential-shaped values (bearer tokens, `sk-` and other provider keys, AWS key IDs, GitHub/Slack tokens, JWTs) are redacted anywhere in cassettes, prompts included, before hashing. Extend with the `redactPatterns` extension parameter.
- A replay miss that the code under test catches and swallows still fails the test.

### Changed
- Cassette format 1.1. 1.0 cassettes still load, but some requests hash differently and need re-recording: requests containing base64 images, audio or files, `raw_http` requests with JSON or multipart bodies, and judge verdicts (the judge prompts changed). Re-record them with `AI_EVAL_MODE=record`, then remove the old entries with `AI_EVAL_PRUNE=1`.
- The binary placeholder in hashed prompts is now `<binary sha256=… size=… mime=…>` (was `<BASE64_SHA256:…>`), and hashes the decoded bytes.
- `AiInteraction::$toolCalls` and `$totalTokens` are plain readonly properties (they used PHP 8.4 asymmetric visibility and a property hook). The judge client is built lazily by a small wrapper instead of a PHP 8.4 lazy proxy.

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
