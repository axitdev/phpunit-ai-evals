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

Laravel's `Http` client runs on Guzzle and doesn't accept a PSR-18 client. Instead, register a global Guzzle middleware that hands every request to the recorder. The recorder sends the live ones through a plain Guzzle client:

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

## System One models (TypeSafe Jev)

No SDK is needed: Jev has one JSON endpoint, so any PSR-18 client works and its calls are recorded like the others. The answers are traced as decisions:

```php
$response = $this->wrapHttpClient(new \GuzzleHttp\Client())->sendRequest(
    $factory->createRequest('POST', 'https://api.typesafe.ai/v1/systemone')
        ->withHeader('Authorization', 'Bearer ' . getenv('TYPESAFE_API_KEY'))
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream(json_encode([
            'model' => 'jev-latest',
            'state' => $ticket,
            'questions' => ['is_urgent' => ['type' => 'noul', 'instructions' => 'The message conveys urgency']],
        ]))),
);

self::assertNoulAtLeast('is_urgent', 0.7);
```

To grade with Jev instead, see [a System One judge](configuration.md#a-system-one-judge).

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
