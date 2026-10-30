# Configuration

Most projects only need the extension in `phpunit.xml` with a `cassettesPath`. Everything below is optional.

## Modes

The mode decides whether a test talks to the real API.

| Mode | Request already recorded | Request not recorded yet | Saves recordings |
|---|---|---|---|
| `auto` (default) | replays it | calls the API, then saves the answer | yes |
| `replay` | replays it | fails with `CassetteMissException` | no |
| `record` | calls the API anyway | calls the API, then saves the answer | yes |
| `live` | calls the API anyway | calls the API | no |

The first of these that is set wins:

1. the `AI_EVAL_MODE` environment variable
2. `#[AiVcr(mode: ...)]` on the test method, then on the class (an `#[AiVcr]` without `mode` is skipped)
3. the extension's `mode` parameter in `phpunit.xml`
4. `auto`

On CI, `auto` becomes `replay`. The library detects CI through the `CI` or `GITHUB_ACTIONS` environment variables (`false` and `0` don't count). Set `AI_EVAL_MODE=record` or `live` there if you really want live calls.

An unknown value, in `AI_EVAL_MODE` or the extension's `mode` parameter, stops the run with the list of valid modes, so a typo can't quietly run in another mode.

## Where cassettes go

The first of these that is set wins: the `AI_EVAL_CASSETTES_PATH` environment variable, the extension's `cassettesPath` parameter (a relative path is resolved from the folder that contains `phpunit.xml`), or `tests/fixtures/ai-cassettes` in the working directory.

Each test method gets its own file, named after the class and method; names over 200 characters are shortened and end in a hash so they stay within filesystem limits. Data provider cases and parallel workers write into the same file safely. The lock files that make this work go to the system temp directory, so nothing else appears next to your cassettes.

## Your own secret names

Common auth headers and credential fields are redacted out of the box (the list is in [How it works](how-it-works.md#secrets)). Add your own with comma-separated, case-insensitive parameters:

```xml
<parameter name="redactHeaders" value="X-Tenant-Token, X-Session"/>
<parameter name="redactKeys" value="session_id, signature"/> <!-- query parameters, form fields and JSON keys -->
```

## Attributes

```php
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;

#[AiVcr(cassette: 'shared-refund-flow', mode: VcrMode::Replay, ignorePatterns: ['/order-\w+/'], ignoreDatesAndUuids: true)]
#[AiJudgeConfig(model: 'gpt-6-luna', baseUri: 'https://api.openai.com/v1', jsonMode: true)]
public function testSomething(): void {}
```

Both attributes work on a method or a class. Each setting on the method wins over the same setting on the class, and the ones the method leaves out come from the class.

`cassette` names the file to use instead of the test name, so several tests can share one cassette. `ignorePatterns` are extra regular expressions removed from prompts before requests are compared. Use them for IDs or text that changes between runs. `ignoreDatesAndUuids: false` keeps dates and UUIDs in the comparison, for prompts that are about them (see [Semantic hashing](how-it-works.md#semantic-hashing)).

## The judge

By default the judge talks the OpenAI Chat Completions protocol, through the HTTP client you passed to `wrapHttpClient()`. Settings come from `#[AiJudgeConfig]` first, then the environment, then the defaults:

| Setting | Environment variable | Default |
|---|---|---|
| Provider (`openai` or `system-one`) | `AI_EVAL_JUDGE_PROVIDER` | `openai` |
| Base URI | `AI_EVAL_JUDGE_BASE_URI` | `https://api.openai.com/v1` |
| Model | `AI_EVAL_JUDGE_MODEL` | `gpt-6-luna` |
| API key | `AI_EVAL_JUDGE_API_KEY`, then `OPENAI_API_KEY` (only when the base URI is `api.openai.com`) | none |
| JSON mode (`response_format: json_object`) | `AI_EVAL_JUDGE_JSON_MODE` (`0`, `false`, `off` or `no` turns it off) | on |
| Temperature | `AI_EVAL_JUDGE_TEMPERATURE` | not sent |

`AI_EVAL_JUDGE_BASE_URI`, `AI_EVAL_JUDGE_MODEL` and `AI_EVAL_JUDGE_API_KEY` belong to the provider set in the environment. A test whose `#[AiJudgeConfig(provider: ...)]` picks another provider gets that provider's defaults instead, so one vendor's key is never sent to another.

Temperature isn't sent unless you set it, because current models reject it: Claude 5 refuses it outright, and the gpt-6 models only accept their default. Replays are deterministic either way, since verdicts are saved. Re-recorded verdicts aren't, so for models that accept a temperature, such as local ones, set it to `0` to have a re-record grade the same answer the same way.

When the judge hits a rate limit (429) or a server error, it retries twice and respects `Retry-After` up to 30 seconds.

Any OpenAI-compatible endpoint works. These were checked against the live APIs:

| Judge | `AI_EVAL_JUDGE_BASE_URI` | JSON mode |
|---|---|---|
| OpenAI (`gpt-6-luna`) | `https://api.openai.com/v1` (the default) | on |
| Claude (`claude-haiku-5-5`) | `https://api.anthropic.com/v1` | off, because Anthropic's compatibility layer rejects `json_object` |
| Gemini (`gemini-3.8-flash`) | `https://generativelanguage.googleapis.com/v1beta/openai` | on |
| Ollama | `http://localhost:11434/v1` | off for models without structured output; also set `AI_EVAL_JUDGE_TEMPERATURE=0` |

The judge's answer is parsed leniently, so turning JSON mode off is safe: the verdict is the last JSON object with a `score`, wherever it sits among markdown fences, notes or a reasoning model's `<think>` block. A temperature or JSON mode value that can't be read stops the run with an error instead of being ignored. `#[AiJudgeConfig(jsonMode: false)]` does the same for one test or class.

To use a judge of your own, call `$this->useJudgeClient($judge)` with any `JudgeClientInterface`. It gets a `JudgeRequest` (a rubric's criteria, or the context for grounding, plus the output) and returns a `JudgeVerdict`. Its `cachePayload()` says what identifies a request, normally what it would send including the model name; verdicts are saved under it, so a replay doesn't call your judge. Pass `cache: false` to call it every time.

### A System One judge

`AI_EVAL_JUDGE_PROVIDER=system-one` (or `#[AiJudgeConfig(provider: 'system-one')]`) grades with a [System One model](https://docs.typesafe.ai/introduction), TypeSafe's Jev by default, at `https://api.typesafe.ai/v1/systemone`. The key comes from `AI_EVAL_JUDGE_API_KEY`, then `TYPESAFE_API_KEY` (only when the base URI is `api.typesafe.ai`). The model defaults to `jev-latest`; JSON mode and temperature don't apply.

A System One model answers small yes/no questions with a probability instead of writing a grade, so each assertion becomes one request with several questions:

- `assertMatchesRubric()` asks one question per criterion: does the output satisfy it?
- `assertNoHallucination()` splits the output into sentences and asks, for each, whether the context supports it.

The score is the lowest probability, so every criterion or sentence must pass the threshold. The model gives no explanation, so the reason lists the weakest answers and the release that gave them, such as `"Stays polite" 0.31; "Gives a number of days" 0.97 [jev-1.13.0]`.

Things to know:

- Give one criterion per thing you check. "Concrete and polite" in one criterion is judged as one question and means less; `['Concrete', 'Polite']` is two.
- Pin a release (`AI_EVAL_JUDGE_MODEL=jev-1.13.0`) if you need re-recorded verdicts to stay comparable: probabilities can change between releases.
- The probabilities are calibrated, not a 0-10 grade turned into 0-1, so thresholds behave differently than with an LLM judge. Start around 0.7 and adjust on your own examples.
- Hallucination checks go sentence by sentence; a claim that needs several sentences together to be wrong can slip through. For those, keep the LLM judge.

Saved verdicts need no API key.

## Custom providers

Requests are matched to a provider by their URL. System One calls (`/v1/systemone`) are built in: their answers are traced as [decisions](assertions.md#what-a-system-one-model-decided). Traffic that matches none is still recorded and replayed (as `raw_http`), just without token counts or tool calls.

To trace another provider, implement `ProviderPayloadParserInterface` and override `aiPayloadParsers()` in your test case or a shared base class:

```php
protected function aiPayloadParsers(): array
{
    return [new AcmePayloadParser(), ...AiVcrClient::defaultParsers()];
}
```

If the provider streams in its own format, implement `StreamingPayloadParserInterface` instead: its `mergeStream()` turns the recorded chunks into the response shape `parse()` reads, and `renderStream()` turns them back into the wire format your code receives on replay. Without it, a stream is traced from its last chunk and replayed as standard SSE.

The first parser whose `supports()` accepts the URL is used. Its `requestPayload()` usually returns the payload unchanged; if the provider puts something in the URL instead of the body, such as the model, add it there so it becomes part of the comparison. The result must contain a `model` key and a prompt (`messages`, `contents`, `input`, `prompt` or `state`); requests without them are recorded as plain `raw_http` traffic, which keeps your app's own endpoints such as `/api/chat` from being mistaken for an LLM.

## Eval report and baseline

```xml
<parameter name="reportPath" value="build/ai-evals.json"/>
<parameter name="baselinePath" value="tests/ai-evals-baseline.json"/>
<parameter name="baselineTolerance" value="0.05"/> <!-- the default -->
```

With `reportPath`, every run writes a JSON file with each test's calls, tokens, latency, judge results (assertion, score, threshold, reason) and pass rates. Keep it as a CI artifact to follow quality over time. The judge's reasons can quote the model's output, so treat the report like your prompts when deciding who may read it. If the report can't be written, the run says why but doesn't fail.

`baselineTolerance` must be a number of at least 0; anything else stops the run with an error.

With `baselinePath`, the run lists every judge score or pass rate that dropped further than the tolerance below the baseline, even if it still passes its threshold. To set or update the baseline, copy a report you're happy with over it. If the file is missing or isn't a report, the run says so instead of comparing. Scores the baseline has no entry for, such as a new test or a reworded rubric, are counted in a note so an outdated baseline can't look like "no regressions". Judge results are keyed by what they assert, without the threshold, so changing a threshold keeps comparing against the baseline. The comparison only prints; it never changes the exit code.

A report looks like this:

```json
{
  "generated_at": "2026-10-22T10:00:00+00:00",
  "totals": { "interactions": 27, "vcrHits": 27, "liveCalls": 0, "promptTokens": 2742, "completionTokens": 2700, "reasoningTokens": 0, "judgePassed": 5, "judgeFailed": 0, "judgeScoreSum": 5.0 },
  "tests": {
    "App\\Tests\\RefundAgentTest::testRefundsADamagedOrder": {
      "interactions": 3, "vcrHits": 3, "liveCalls": 0, "promptTokens": 741, "completionTokens": 384, "reasoningTokens": 0, "latencyMs": 9120.4,
      "judge": [{ "assertion": "satisfies the rubric \"Tells the customer …\"", "score": 1.0, "threshold": 0.8, "passed": true, "reason": "…" }],
      "passRates": []
    }
  }
}
```

## Removing old recordings

Re-recording replaces answers, but recordings that no request matches anymore stay in the file. Run the whole suite with `AI_EVAL_PRUNE=1` to remove them (any value except empty, `0` and `false` turns it on, as with `CI`). After a run where every test passed, each cassette the run used keeps only what was replayed or recorded.

Don't combine pruning with `--filter` or a parallel runner: recordings used only by tests outside that run would be removed. Cassette files of deleted tests are left alone; delete those yourself.

## Parallel runs and process isolation

Cassettes are safe to share between processes, because every write takes a file lock. Everything else the extension tracks lives inside one PHPUnit process:

- Under [ParaTest](https://github.com/paratestphp/paratest), each worker writes its own report (`ai-evals.json` becomes `ai-evals.worker-<n>.json`) and prints its own summary. Merge the reports with a tool such as `jq` if you need one file.
- Tests that run in a separate process (`#[RunInSeparateProcess]`) record and replay normally but don't appear in the summary or the report.
- Don't prune under ParaTest; one worker would remove recordings another worker used.
