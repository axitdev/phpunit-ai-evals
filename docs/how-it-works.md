# How it works

You don't need any of this to use the library. It's here for when a recording doesn't match the way you expected, or when you want to know exactly what ends up in a cassette.

## Semantic hashing

Before a request is looked up, it's reduced to the parts that decide what you asked, and those are hashed. Two requests that ask the same thing get the same hash even if they differ in key order, timestamps or sampling settings.

`PromptNormalizer` keeps only these fields: `model`, `messages` / `contents` / `system` / `input` / `prompt`, System One's `state` and `questions`, `tools`, `tool_choice`, `response_format`, `stream` (where `false` counts as absent), the Responses API's `instructions`, `previous_response_id` and `text`, and the Gemini and Ollama equivalents. Settings that change what comes back are kept too: reasoning (`thinking`, `reasoning`, `reasoning_effort`, Ollama's `think`), Anthropic's `output_config` / `output_format`, and from Gemini's `generationConfig` only `responseMimeType`, `responseSchema`, `responseJsonSchema`, `responseModalities` and `thinkingConfig`. Everything else, such as `temperature`, `max_tokens` or `seed`, is ignored, so changing it replays the old recording. Then it:

- sorts keys at every level, and re-encodes the JSON strings in `tool_calls[].function.arguments` with sorted keys
- replaces ISO-8601 dates and timestamps with `<TIMESTAMP>`, UUIDs with `<UUID>`, `data:...;base64,` blobs with `<BASE64_SHA256:...>`, and matches of your `ignorePatterns` with `<IGNORED>`. A date inside a longer token, such as the phone number `555-1234-56-78`, is left alone
- removes the signatures of Anthropic `thinking` blocks
- hashes the result with SHA-256

Because dates and UUIDs become placeholders, two prompts that differ only in a date ("Is 2025-12-25 a holiday?" and "Is 2025-07-04 a holiday?") hash the same. They still replay correctly in the order they were sent, but changing only the date doesn't make a recording miss. When the dates or IDs are what the prompt is about, keep them with `#[AiVcr(ignoreDatesAndUuids: false)]`.

Some providers put part of the request in the URL: the model for Gemini, Claude on Vertex and Bedrock, the deployment for Azure OpenAI, and streaming for Gemini (`:streamGenerateContent`) and Bedrock (`/converse-stream`). Each provider's parser adds those to the payload through `requestPayload()`, so they're part of the hash too.

Other HTTP traffic (`raw_http`) is hashed on its method, URL and whole body, after the same date, UUID and `ignorePatterns` replacements, so a search request that carries the current time still replays.

## Matching

A request matches a recording when the kind, the hash and the occurrence number all agree. The occurrence number counts how often the same request has been sent in the current test, across every client the test wrapped. That's how retries and repeated steps in an agent loop replay the 1st, 2nd and Nth answer in order. A request whose answer isn't recorded (a rate limit, a server error, a dropped connection) doesn't count, so the retry that succeeds is stored where a replay, which never sees the failure, looks for it.

When a request has no recording in `replay` mode, `CassetteMissException` shows a diff against the closest recorded prompt and the command that re-records the test.

## Cassette format

```json
{
  "version": "1.0",
  "test": "App\\Tests\\RefundAgentTest::testAgentProcessesValidRefund",
  "interactions": [
    { "kind": "agent_call", "semantic_hash": "…", "occurrence_index": 0, "provider": "openai",
      "request": { "uri": "…?key=[REDACTED]", "headers": { "Authorization": ["[REDACTED]"] }, "normalized_payload": {} },
      "response": { "status": 200, "headers": {}, "is_stream": false, "body": {} },
      "trace": { "prompt_tokens": 140, "completion_tokens": 35, "reasoning_tokens": 0, "latency_ms": 420.5, "tool_calls": [] } },
    { "kind": "judge_eval", "semantic_hash": "…", "occurrence_index": 0, "verdict": { "score": 0.95, "reason": "…" } },
    { "kind": "raw_http", "…": "other HTTP traffic, recorded without token or tool parsing" }
  ]
}
```

Response bodies are stored as readable JSON when that reproduces them exactly. Otherwise the original text is kept: PHP can't tell `{}` from `[]` once decoded, so a body such as OpenAI's `"metadata": {}` is stored as a string. Bodies that aren't valid UTF-8, such as images, are stored as `body_base64`. Either way they replay byte for byte.

Writes are atomic: the file is read, merged and replaced through `rename()` under an exclusive lock. The lock file lives in a folder of the system temp directory that only your user can access, so another user of a shared machine can't block your runs.

Rate limits and timeouts (408, 429), rejected credentials (401, 403, 407), server errors (5xx) and streams that report an error partway through, such as Anthropic's `overloaded_error` event inside an HTTP 200 stream, are passed to your code but never recorded, so neither a temporary failure nor an expired key can end up replaying forever. Other errors, such as a 400, are recorded and replayed like any answer.

`version` changes only when existing cassettes stop replaying. A cassette with a different major version is refused with an error rather than read wrongly; one without `version`, such as a hand-written one, is read as the current format. If a release changes how requests are hashed, the changelog says so and which cassettes to re-record.

## Secrets

Cassettes contain your prompts and the model's answers in full. Before anything is written:

- These request and response headers become `[REDACTED]`: `Authorization`, `Proxy-Authorization`, `x-api-key`, `api-key`, `x-goog-api-key`, `X-Amz-Security-Token`, `Cookie` and `Set-Cookie`, plus the account identifiers `OpenAI-Organization`, `OpenAI-Project` and `anthropic-organization-id`.
- So are headers whose name ends in one of these words, after a `-` or `_`: `key`, `api-key`, `access-key`, `private-key`, `auth`, `authorization`, `token`, `secret`, `password`, `passwd`, `signature`, `cookie`. That catches gateway headers such as `x-portkey-api-key`, `Ocp-Apim-Subscription-Key` or `cf-aig-authorization`. The plural `tokens` doesn't count, so rate-limit headers like `x-ratelimit-remaining-tokens` stay readable.
- URLs lose their `user:password@` part, and these query parameters are redacted: `key`, `api_key`, `apikey`, `access_token`, `refresh_token`, `id_token`, `token`, `client_secret`, `password` and `secret`, plus any name ending in one of the words above (except `key` and `cookie`), `credential(s)` or `client_assertion`, such as `session_token` or `aws_secret_access_key`. Names that only start with one, such as `token_count`, are left alone.
- For `raw_http` traffic, such as OAuth token calls, the same names except `key` are redacted in JSON and form-encoded bodies, at any depth, in both directions. `true`, `false` and `null` values are kept, since they're never credentials. This happens before hashing, so a rotated secret still replays. The replayed response then carries `[REDACTED]` instead of the real token.
- The extension's `redactHeaders` and `redactKeys` parameters add names to these lists (see [Configuration](configuration.md#your-own-secret-names)).

LLM request and response bodies aren't scrubbed beyond the steps above, so keep credentials out of prompts. Also note that in `replay` mode a missing recording fails with a diff of the prompt, which ends up in your CI logs. If your prompts contain sensitive data, think about who can read those logs.

## Provider support

| Provider | Endpoints | Text | Tool calls | Streaming | Checked against |
|---|---|---|---|---|---|
| Anthropic | `/v1/messages` | yes | yes | SSE | Live API, `claude-haiku-5-5`, 2026-10-22, including prompt-cache tokens |
| Gemini | `:generateContent`, `:streamGenerateContent` | yes | yes | SSE (`?alt=sse`) and JSON array | Live API, `gemini-3.8-flash`, 2026-10-22 |
| Ollama | `/v1/chat/completions`, `/api/chat`, `/api/generate` | yes | yes (`/v1`) | NDJSON (`/api/chat`) | Local Ollama, `gemma4:e4b`, 2026-10-22 ([examples](https://github.com/axitdev/phpunit-ai-evals-examples)) |
| OpenAI Chat Completions and compatible APIs | `/chat/completions` | yes | yes | SSE | Live API, `gpt-6-luna`, 2026-10-22, and Ollama's compatible endpoint |
| Azure OpenAI Chat Completions | `/openai/deployments/{deployment}/chat/completions` (the deployment counts as the model) | yes | yes | SSE | Not yet; built from the documented formats |
| OpenAI Responses | `/responses` | yes | yes | SSE | Live API, `gpt-6-luna`, 2026-10-22 |
| Claude on Google Vertex AI | `…/publishers/anthropic/models/…:rawPredict`, `:streamRawPredict` | yes | yes | SSE | Not yet; built from the documented formats |
| Amazon Bedrock Converse | `/model/…/converse`, `/converse-stream` | yes | yes | AWS binary event stream | Not yet; built from the documented formats |
| System One (TypeSafe Jev) | `/v1/systemone` | typed answers ([decisions](assertions.md#what-a-system-one-model-decided)) | no | no | Not yet; built from the documented formats (`jev-1.13.0`) |

Embeddings and other endpoints that aren't listed are recorded and replayed as `raw_http`, without token counts.

"Checked against" means a real request was recorded, its tokens, text and tool calls were read correctly, and replaying it gave the same result. You can run the same check with your own keys:

```bash
ANTHROPIC_API_KEY=… GEMINI_API_KEY=… OPENAI_API_KEY=… TYPESAFE_API_KEY=… vendor/bin/phpunit tests/Live
```

Providers without a key are skipped. The suite sends about 25 short requests to the models in the table, and also tries Claude, Gemini and OpenAI as [judges](configuration.md#the-judge).

## Streaming

Provider streams are stored as readable JSON chunks. That covers `text/event-stream`, Ollama's `application/x-ndjson` and Bedrock's binary `application/vnd.amazon.eventstream`. Replay turns the chunks back into the original format:

- `data: …` lines plus `data: [DONE]` for OpenAI Chat Completions
- `event:` lines for Anthropic and the Responses API
- one JSON object per line for Ollama
- CRC-checked binary frames for Bedrock

Streams from anything that isn't a known provider are recorded and replayed exactly as they came. A [custom parser](configuration.md#custom-providers) that streams can implement `StreamingPayloadParserInterface` to merge and re-render its own chunks.

Token counts and tool calls are rebuilt from the chunks. OpenAI Chat Completions streams only report tokens when you send `stream_options.include_usage`. Anthropic prompt-cache reads and writes count as prompt tokens.

## Judge caching

The judge looks for a saved verdict first and only sends a request when it's missing. Any judge is cached the same way, the built-in ones and your own, under the judge's `cachePayload()`: what it would send, including the model.

A verdict is saved only when the judge answers with a `score` between 0 and 1. An answer without one, or on another scale such as `{"score": 7}`, fails the assertion with an error and nothing is saved, so a misbehaving judge can't pass a test. Verdicts are keyed on the judge model and the prompts (for a System One judge, the state and questions); like `temperature` for agent calls, changing the judge's base URI, temperature or JSON mode reuses saved verdicts until you re-record.

## The PHPUnit extension

`AiEvalsExtension` keeps track of which test is running. When a test finishes, it drops the full trace and keeps only the numbers. After PHPUnit's own output it prints the summary, writes the report and, with `AI_EVAL_PRUNE=1`, prunes unused recordings.
