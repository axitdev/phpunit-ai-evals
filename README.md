# PHPUnit AI Evals

[![CI](https://github.com/axitdev/phpunit-ai-evals/actions/workflows/ci.yml/badge.svg)](https://github.com/axitdev/phpunit-ai-evals/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/axitdev/phpunit-ai-evals)](https://packagist.org/packages/axitdev/phpunit-ai-evals)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> [!WARNING]
> **Pre-release and experimental.** The public API, cassette format, and request-matching logic are subject to change. Feedback is welcome via [Issues](https://github.com/axitdev/phpunit-ai-evals/issues) and Pull Requests.

Deterministic evaluations, semantic assertions, and regression testing for LLM pipelines in PHPUnit—without flaky tests or recurring API costs.

The first time a test runs, LLM calls hit the real provider API and are recorded to a local JSON "cassette". Subsequent runs replay the recorded responses offline in milliseconds. You can assert on execution traces (tool calls, token counts), test for prompt regressions, and run LLM-as-a-judge checks whose verdicts are also cached for zero-cost CI runs.

Works out of the box with any **PSR-18 HTTP client** (Guzzle, Symfony HttpClient, `openai-php/client`, Anthropic SDK, Prism), and with Laravel `Http` through `Http::fake()`, so existing feature tests gain cassettes without rewrites. Compatible with OpenAI, Anthropic, Gemini, Ollama, and AWS Bedrock (streaming included).

**Requirements:** PHP 8.3+ and PHPUnit 12.5 or 13 (13 needs PHP 8.4).

---

## What You Can Test

### 1. Agent Tool Calling & Execution Traces

Verify that autonomous agents follow required policies, call tools in the correct order, and pass expected arguments:

```php
$answer = $agent->handle('My order #404 arrived broken. Can I get my money back?');

self::assertToolCallOrder(['lookup_order', 'issue_refund']);
self::assertToolCalledTimes('issue_refund', 1, ['order_id' => 404]);
self::assertToolNotCalled('delete_account');
```

### 2. Hallucination & Semantic Grading (LLM-as-a-Judge)

For RAG pipelines, ensure output is strictly grounded in retrieved context. The judge model evaluates the answer once, and the evaluation score is committed with the cassette:

```php
$docs = $retriever->search('How long do refunds take?');
$answer = $bot->answer('How long do refunds take?', $docs);

self::assertNoHallucination(['The customer asked how long refunds take.', ...$docs], $answer);
self::assertMatchesRubric('Gives a concrete number of days and stays polite', $answer);
```

Rubrics and answers can be in any language. Grade every translation of an answer at once, and see which one failed:

```php
$this->assertVariantsMatchRubric('Gives a concrete number of days', ['en' => $en, 'pl' => $pl, 'uk' => $uk]);
$this->assertSemanticallySimilar('Refunds take 5 business days.', $polishAnswer);
```

### 3. Dataset Benchmarking & Schema Validation

Run evaluations across JSONL/CSV test suites and require an overall pass-rate threshold rather than fragile absolute matches:

```php
self::assertPassRate(Dataset::load(__DIR__ . '/datasets/reviews.jsonl'), 0.8, function (array $case): void {
    $json = $this->classifier->classify($case['review']);

    self::assertMatchesJsonSchema(self::SCHEMA, $json);
    self::assertFieldIn('sentiment', ['positive', 'neutral', 'negative'], $json);
    self::assertScoreBetween('confidence', 0.0, 1.0, $json);
    self::assertSame($case['expected'], json_decode($json, true)['sentiment']);
});
```

### 4. Catching Prompt Regressions in Pull Requests

In CI, tests run strictly in replay mode. Any prompt modification alters the semantic hash, failing the test with a readable diff:

```text
No recorded agent_call with semantic_hash 3f2a… in cassette.
Cassette: tests/fixtures/ai-cassettes/App_Tests_RefundAgentTest_testRefund.json

Differs from the closest recorded interaction in: messages.0.content
  messages.0.content
    recorded: "You are a support agent."
    current:  "You are a friendly support agent."

Re-record with: AI_EVAL_MODE=record vendor/bin/phpunit --filter 'RefundAgentTest::testRefund\b'
```

### 5. Token & Latency Budgets

Prevent prompt bloat and runaway loops before hitting production:

```php
self::assertTokenUsageLessThan(2000); // Total prompt + completion tokens
self::assertLatencyLessThan(15_000);  // Milliseconds recorded during initial run
```

### 6. Sampling Non-Deterministic Behaviors

For non-deterministic tasks, record multiple runs and enforce an acceptable passing ratio:

```php
self::assertSampled(runs: 5, minPass: 4, testLogic: function (): void {
    $this->agent->handle('My order #404 arrived broken.');
    self::assertToolCalled('issue_refund');
});
```

### 7. Multi-Turn Conversations

Script the user's side of a conversation and assert after every turn, with the full history at hand. Each turn is recorded on its own, so editing one turn re-records only that turn:

```php
$this->runConversation('refund', fn(Conversation $c) => $this->bot->reply($c->messages()), [
    Turn::user('My order arrived broken.', fn(string $reply) => self::assertStringContainsString('order number', $reply)),
    Turn::user('It is #404.', function (string $reply, Conversation $c): void {
        self::assertToolCalled('lookup_order', ['order_id' => 404], $c->turn(2)->interactions);
    }),
]);
```

### 8. Request Matching You Control

Leave fields out of matching or accept any value for them, per test or for the whole suite, so recordings survive a model switch or a per-request ID. Requests with images and file uploads are matched by content hash; the bytes never reach the cassette.

```php
#[AiVcr(ignoreFields: ['model'], normalizeFields: ['metadata.request_id'], matchHeaders: ['X-Tenant'])]
```

---

## Quick Start

### Installation

```bash
composer require --dev axitdev/phpunit-ai-evals
```

> **Note:** Requires a PSR-17 factory implementation. If your application does not have one, install `composer require --dev nyholm/psr7`.

### 1. Register the PHPUnit Extension

Add the extension to `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Axitdev\PhpUnitAiEvals\PhpUnit\AiEvalsExtension">
        <parameter name="cassettesPath" value="tests/fixtures/ai-cassettes"/>
    </bootstrap>
</extensions>
```

### 2. Wrap Your Client & Write Tests

```php
use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use PHPUnit\Framework\TestCase;

final class RefundAgentTest extends TestCase
{
    use AiAssertionsTrait;

    public function testRefundsADamagedOrder(): void
    {
        // Wrap the standard PSR-18 client used by your agent/SDK
        $agent = new RefundAgent($this->wrapHttpClient(new \GuzzleHttp\Client()));

        $answer = $agent->handle('Refund order #404, it arrived broken');

        self::assertToolCalled('issue_refund', ['order_id' => 404]);
        self::assertMatchesRubric('Confirms the refund politely', $answer);
    }
}
```

### 3. Record and Replay

Run tests with provider API keys configured. The run generates a cassette at `tests/fixtures/ai-cassettes/<Class>_<method>.json`:

```bash
# Subsequent runs replay offline:
vendor/bin/phpunit --filter testRefundsADamagedOrder

# Re-record when prompts or models change:
AI_EVAL_MODE=record vendor/bin/phpunit --filter testRefundsADamagedOrder

# Clean up unused cassettes:
AI_EVAL_PRUNE=1 vendor/bin/phpunit
```

Each run displays an evaluation summary:

```text
AI Evals Summary:
  Interactions: 14 total (12 VCR hits, 2 live calls)
  Tokens Used:  4,210 (Prompt: 3,400 / Completion: 810 / Reasoning: 120)
  Judge Evals:  8 passed (Avg Score: 0.94)
```

Working examples are available in the [examples repository](https://github.com/axitdev/phpunit-ai-evals-examples).

---

## Documentation

* [FAQ](docs/faq.md) — Common questions and troubleshooting.
* [Integrations](docs/integrations.md) — Guzzle, Symfony, openai-php, Anthropic SDK, Laravel Http (`fakeAiHttp()`), Prism, and local models.
* [Assertions Reference](docs/assertions.md) — Full assertion list, structured output, other languages, conversations, datasets, and probabilistic sampling.
* [Configuration](docs/configuration.md) — Modes, cassette storage, request matching, judge setup, reports, and parallel test execution.
* [How It Works](docs/how-it-works.md) — Request hashing, cassette formats, secret redaction, streaming, and provider support.

---

## Development

```bash
composer install
composer test        # Run PHPUnit test suite
composer phpstan     # Run PHPStan (level max)
composer cs          # Check code style with PHP CS Fixer (composer cs-fix to apply)
composer infection   # Run mutation tests (requires pcov or Xdebug)
```

Live provider tests are located in `tests/Live` and are run against actual APIs. See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

---

## License

MIT. See [LICENSE](LICENSE).
