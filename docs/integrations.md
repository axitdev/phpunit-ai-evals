# Integrations

`wrapHttpClient()` takes a PSR-18 client and gives you back one that records and replays. Pass it to whatever talks to your LLM. Every recipe on this page runs in the [examples repository](https://github.com/axitdev/phpunit-ai-evals-examples).

## Plain PSR-18 (Guzzle, Symfony, Buzz, …)

If your code takes a `Psr\Http\Client\ClientInterface`, pass the wrapped client in its place:

```php
$agent = new RefundAgent($this->wrapHttpClient(new \GuzzleHttp\Client()), /* ... */);
```

Symfony HttpClient's PSR-18 adapter can also create requests and streams (it's a PSR-17 factory), so one object covers all three:

```php
$psr18 = new \Symfony\Component\HttpClient\Psr18Client();
$summarizer = new StreamingSummarizer($this->wrapHttpClient($psr18), $psr18, $psr18, /* ... */);
```

## openai-php/client

```php
$openAi = \OpenAI::factory()
    ->withApiKey(getenv('OPENAI_API_KEY'))
    ->withHttpClient($this->wrapHttpClient(new \GuzzleHttp\Client()))
    ->make();
```

Add `withBaseUri()` to point it at any OpenAI-compatible API, for example `http://localhost:11434/v1` for Ollama.

## Anthropic PHP SDK (`anthropic-ai/sdk`)

Pass the wrapped client as both transporters, so plain and streaming calls are both recorded:

```php
use Anthropic\Client;
use Anthropic\RequestOptions;

$vcr = $this->wrapHttpClient(new \GuzzleHttp\Client());
$anthropic = new Client(requestOptions: RequestOptions::with(transporter: $vcr, streamingTransporter: $vcr));
```

Checked against the live API with `anthropic-ai/sdk` 1.1: `messages->create()` and `messages->createStream()` both record, show up in the trace and replay offline.

## Laravel's HTTP client and Prism

Add the `FakesAiHttp` trait next to `AiAssertionsTrait` and call `fakeAiHttp()`. Existing feature tests then get cassettes without other changes: calls made with `Http` (and so with Prism) go through an `Http::fake()` callback into the recorder.

```php
use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Laravel\FakesAiHttp;

final class SummaryTest extends TestCase
{
    use AiAssertionsTrait, FakesAiHttp;

    public function test_it_summarises_an_upload(): void
    {
        $this->fakeAiHttp(['api.openai.com/*', 'api.anthropic.com/*']);

        $this->post('/summaries', ['file' => UploadedFile::fake()->image('scan.png')])->assertOk();

        self::assertToolCalled('save_summary');
    }
}
```

- Replays come from the cassette. Record mode, and auto mode when a request isn't recorded yet, send the call to the real provider through a plain Guzzle client, with the original call's timeouts, proxy and TLS options, and write the cassette.
- Pass URL patterns as you would to `Http::fake()`; the default `*` records every call. In `replay` mode the bridge also calls `Http::preventStrayRequests()`, so a call outside the patterns fails instead of reaching the network.
- Laravel's own `Http::assertSent()` and friends keep working.
- `fakeAiHttp()` takes an optional `Factory` (instead of the facade) and an optional PSR-18 client for live calls, and returns the recorder.

It needs `illuminate/http` 11 or 12, which a Laravel app already has.

If you'd rather not fake `Http`, a global Guzzle middleware that hands every request to the recorder works too. The recorder sends the live ones through a plain Guzzle client:

```php
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

protected function setUp(): void
{
    parent::setUp();

    $vcr = $this->wrapHttpClient(new \GuzzleHttp\Client());
    Http::globalMiddleware(fn(callable $next): callable =>
        fn(RequestInterface $request, array $options) => Create::promiseFor($vcr->sendRequest($request)));
}
```

Prism sends its requests through `Http`, so this covers Prism too. Outside a Laravel app, call `globalMiddleware()` on an `Illuminate\Http\Client\Factory` instance.

## Other Guzzle-based SDKs

An SDK that accepts a Guzzle client or handler, but not a PSR-18 client, can use the same middleware:

```php
$stack = \GuzzleHttp\HandlerStack::create();
$stack->push(fn(callable $next): callable => fn(RequestInterface $request, array $options) => Create::promiseFor($vcr->sendRequest($request)));
$sdk = new SomeSdk(new \GuzzleHttp\Client(['handler' => $stack]));
```

## Local models and other judges

The judge talks the OpenAI Chat Completions protocol, so Claude, Gemini and local models can grade too (see the [judge settings](configuration.md#the-judge)). For a local Ollama:

```xml
<php>
    <env name="AI_EVAL_JUDGE_BASE_URI" value="http://localhost:11434/v1"/>
    <env name="AI_EVAL_JUDGE_MODEL" value="gemma4:e4b"/>
    <env name="AI_EVAL_JUDGE_JSON_MODE" value="0"/> <!-- some models reject response_format -->
    <env name="AI_EVAL_JUDGE_TEMPERATURE" value="0"/> <!-- the same answer gets the same grade when you re-record -->
</php>
```
