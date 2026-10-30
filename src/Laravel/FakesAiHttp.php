<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Laravel;

use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;

/**
 * Gives Laravel feature tests cassettes without rewriting them: calls made with the `Http` client are routed through
 * the VCR from an Http::fake() callback. Replays come from the cassette; record mode (and auto mode on a miss) sends
 * the call to the real provider and writes the cassette.
 *
 * Use it together with AiAssertionsTrait:
 *
 *     use AiAssertionsTrait, FakesAiHttp;
 *
 *     public function test_summary(): void
 *     {
 *         $this->fakeAiHttp('api.openai.com/*');
 *         $this->postJson('/summaries', ['text' => '...'])->assertOk();
 *         self::assertToolCalled('save_summary');
 *     }
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait FakesAiHttp
{
    abstract protected function wrapHttpClient(ClientInterface $client): AiVcrClient;

    /**
     * @param string|list<string> $urls Http::fake() URL patterns to put on cassette; `*` is every call
     * @param Factory|null $http Defaults to the Http facade's factory
     * @param ClientInterface|null $live Where record mode sends calls; defaults to a plain Guzzle client
     */
    protected function fakeAiHttp(string|array $urls = '*', ?Factory $http = null, ?ClientInterface $live = null): AiVcrClient
    {
        $http ??= Http::getFacadeRoot();
        assert($http instanceof Factory);
        $passthrough = $live ?? new PassthroughClient();
        $vcr = $this->wrapHttpClient($passthrough);

        $callback = static function (Request $request, array $options) use ($vcr, $passthrough): PromiseInterface {
            if ($passthrough instanceof PassthroughClient) {
                $passthrough->useOptions($options);
            }

            return Create::promiseFor($vcr->sendRequest($request->toPsrRequest()));
        };
        $http->fake(array_fill_keys((array) $urls, $callback));

        // Calls outside the patterns would reach the network; replay mode promises they never do.
        if ($vcr->mode() === VcrMode::Replay) {
            $http->preventStrayRequests();
        }

        return $vcr;
    }
}
