# phpunit-ai-evals

[![CI](https://github.com/axitdev/phpunit-ai-evals/actions/workflows/ci.yml/badge.svg)](https://github.com/axitdev/phpunit-ai-evals/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/axitdev/phpunit-ai-evals)](https://packagist.org/packages/axitdev/phpunit-ai-evals)

> [!WARNING]
> **Pre-release and experimental.** This is version 0.0.2. The API, the cassette format and the way requests are matched can still change without notice, so please don't use it in projects you depend on yet.
>
> Feedback is very welcome. Open an [issue](https://github.com/axitdev/phpunit-ai-evals/issues) to report a problem or discuss an idea, and pull requests are welcome too.

Test the parts of your PHP app that talk to an LLM, with PHPUnit, without paying for API calls or fighting flaky answers on every run.

The first time a test runs, its LLM calls go to the real API and get saved to a JSON file next to your tests (a "cassette"). After that the test replays the saved answers, offline, in milliseconds. You can then check what the model did (which tools it called, how many tokens it used) and ask a second model to grade what it said. Those grades are saved too, so CI never needs an API key.

It works with any PSR-18 HTTP client, so your code doesn't change: Guzzle, Symfony, openai-php/client, the Anthropic SDK, Laravel's `Http` and Prism all work. It understands OpenAI (Chat Completions and Responses), Anthropic (also on Vertex AI), Gemini, Ollama and Amazon Bedrock, streaming included.

Requires PHP 8.4+ (with mbstring) and PHPUnit 12.5 or 13.

## What you can test

### An agent picks the right tools

A support agent should look up an order before refunding it, refund exactly once, and never touch account deletion. Those are facts about the trace, so you can assert them exactly:

```php
$answer = $agent->handle('My order #404 arrived broken. Can I get my money back?');

self::assertToolCallOrder(['lookup_order', 'issue_refund']);
self::assertToolCalledTimes('issue_refund', 1, ['order_id' => 404]);
self::assertToolNotCalled('delete_account');
```

### Answers stay grounded in your data

For a RAG bot, check that the answer only claims what the retrieved documents say. A judge model scores it, and the score is saved with the cassette:

```php
$docs = $retriever->search('How long do refunds take?');
$answer = $bot->answer('How long do refunds take?', $docs);

$this->assertNoHallucination(['The customer asked how long refunds take.', ...$docs], $answer);
$this->assertMatchesRubric('Gives a concrete number of days and stays polite', $answer);
```

### A classifier stays accurate across many cases

Keep test cases in a JSONL or CSV file and require a pass rate instead of perfection. Each answer is also checked against a JSON Schema:

```php
$this->assertPassRate(Dataset::load(__DIR__ . '/datasets/reviews.jsonl'), 0.8, function (array $case): void {
    $json = $this->classifier->classify($case['review']);

    self::assertMatchesJsonSchema(self::SCHEMA, $json);
    self::assertSame($case['expected'], json_decode($json, true)['sentiment']);
});
```

### Prompt changes show up in code review

In CI the tests only replay. If someone edits a prompt, the matching recording no longer fits and the test fails with a diff:

```text
No recorded agent_call with semantic_hash 3f2a… (occurrence #0) in cassette.
Cassette: tests/fixtures/ai-cassettes/App_Tests_RefundAgentTest_testRefund.json

Diff against the closest recorded interaction (- recorded, + current):
-             "content": "You are a support agent."
+             "content": "You are a friendly support agent."

Re-record with: AI_EVAL_MODE=record vendor/bin/phpunit --filter 'RefundAgentTest::testRefund\b'
```

The author re-records, and the new answers land in the same pull request, where reviewers can read them.

### Token and latency budgets

Catch a prompt change that quietly doubles the context, or an agent loop that runs too many turns:

```php
self::assertTokenUsageLessThan(2000);   // all turns together
self::assertLatencyLessThan(15_000);    // milliseconds, as measured when recorded
```

### Behaviour that is right most of the time

Some behaviour is only reliable most of the time. Record several attempts and require most of them to pass. Each attempt is recorded separately, so the replay keeps the same mix of passes and failures:

```php
$this->assertSampled(runs: 5, minPass: 4, testLogic: function (): void {
    $this->agent->handle('My order #404 arrived broken.');
    self::assertToolCalled('issue_refund');
});
```

### Quality drifting between model versions

Write a JSON report after every run and compare it with a saved baseline. The run lists every judge score or pass rate that dropped, even when it still passes its threshold.

## Quick start

```bash
composer require --dev axitdev/phpunit-ai-evals
```

You also need any PSR-17 implementation; `composer require --dev nyholm/psr7` works if your project has none. Composer may ask whether to allow the `php-http/discovery` plugin. Answering no is fine.

Register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Axitdev\PhpUnitAiEvals\PhpUnit\AiEvalsExtension">
        <parameter name="cassettesPath" value="tests/fixtures/ai-cassettes"/>
    </bootstrap>
</extensions>
```

Then wrap the HTTP client your code already uses:

```php
use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use PHPUnit\Framework\TestCase;

final class RefundAgentTest extends TestCase
{
    use AiAssertionsTrait;

    public function testRefundsADamagedOrder(): void
    {
        $agent = new RefundAgent($this->wrapHttpClient(new \GuzzleHttp\Client()));

        $answer = $agent->handle('Refund order #404, it arrived broken');

        self::assertToolCalled('issue_refund', ['order_id' => 404]);
        $this->assertMatchesRubric('Confirms the refund politely', $answer);
    }
}
```

Run it once with your API keys set. That writes `tests/fixtures/ai-cassettes/<Class>_<method>.json`. Commit the file; every later run, including CI, replays it. Cassettes contain your prompts and the model's answers, so read [what gets redacted](docs/how-it-works.md#secrets) before you commit them.

Each run ends with a summary:

```text
AI Evals Summary:
  Interactions: 14 total (12 VCR hits, 2 live calls)
  Tokens Used:  4,210 (Prompt: 3,400 / Completion: 810 / Reasoning: 120)
  Judge Evals:  8 passed (Avg Score: 0.94)
```

Two commands you'll use often:

```bash
AI_EVAL_MODE=record vendor/bin/phpunit --filter testRefundsADamagedOrder   # re-record one test
AI_EVAL_PRUNE=1 vendor/bin/phpunit                                          # drop recordings no test uses anymore
```

The [examples repository](https://github.com/axitdev/phpunit-ai-evals-examples) has all of this running with real recorded cassettes.

## Documentation

- [FAQ](docs/faq.md): the questions people ask first
- [Integrations](docs/integrations.md): Guzzle, Symfony, openai-php/client, the Anthropic SDK, Laravel `Http` and Prism, local models
- [Assertions](docs/assertions.md): every assertion, datasets and sampling
- [Configuration](docs/configuration.md): modes, cassettes, the judge, reports, parallel runs
- [How it works](docs/how-it-works.md): matching, the cassette format, secrets, streaming, provider support

## Development

```bash
composer install
composer test        # PHPUnit
composer phpstan     # PHPStan, level max
composer cs          # PHP CS Fixer (composer cs-fix applies it)
composer infection   # mutation testing; needs pcov or Xdebug
```

`tests/Live` checks the parsers against the real provider APIs and isn't part of `composer test`. See [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md) for reporting vulnerabilities.

## License

MIT, see [LICENSE](LICENSE).
