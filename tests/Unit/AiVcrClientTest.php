<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\OneShotStream;
use Axitdev\PhpUnitAiEvals\Tests\Support\VcrTestTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Recording, replaying, matching and redaction.
 */
final class AiVcrClientTest extends TestCase
{
    use VcrTestTrait;

    private const URI = 'https://api.openai.com/v1/chat/completions?key=SECRET';

    public function testRecordWritesScrubbedCassetteAndTracesInteraction(): void
    {
        $inner = new FakeClient(FakeClient::json(FakeClient::openAiReply('ok', [['issue_refund', ['order_id' => 404]]], 140, 35)));
        $client = $this->client($inner, VcrMode::Record);

        $response = $client->sendRequest(FakeClient::request(self::URI, $this->prompt('Refund order #404')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', Payload::get(json_decode((string) $response->getBody(), true), 'choices', 0, 'message', 'content'));
        $trace = $this->collector->last();
        self::assertSame(175, $trace?->totalTokens);
        self::assertSame('issue_refund', $trace->toolCalls[0]->name ?? null);

        $entry = $this->cassette()->interactions()[0];
        self::assertSame('agent_call', $entry['kind']);
        self::assertSame('openai', $entry['provider']);
        self::assertSame(0, $entry['occurrence_index']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, Payload::string($entry['recorded_at'] ?? null)));
        self::assertSame('https://api.openai.com/v1/chat/completions?key=[REDACTED]', Payload::get($entry, 'request', 'uri'));
        self::assertSame(['[REDACTED]'], Payload::get($entry, 'request', 'headers', 'Authorization'));
        self::assertNull(Payload::get($entry, 'request', 'normalized_payload', 'temperature'));
        self::assertSame(140, Payload::get($entry, 'trace', 'prompt_tokens'));
        self::assertSame(['name' => 'issue_refund', 'arguments' => ['order_id' => 404]], Payload::get($entry, 'trace', 'tool_calls', 0));
        self::assertStringNotContainsString('SECRET', $this->cassette()->toJson());
        self::assertStringNotContainsString('sk-secret', $this->cassette()->toJson());
    }

    public function testReplayServesCassetteWithoutLiveCall(): void
    {
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('hello'))), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('hi')));

        $offline = new FakeClient();
        $client = $this->client($offline, VcrMode::Replay);
        $response = $client->sendRequest(FakeClient::request(self::URI, $this->prompt('hi') + ['seed' => 7]));

        self::assertSame([], $offline->requests);
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('hello', $this->collector->last()?->assistantContent);
    }

    public function testReplayMatchesDespiteDynamicTimestamps(): void
    {
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('ok'))), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('now is 2026-10-22T01:00:00Z')));

        $client = $this->client(new FakeClient(), VcrMode::Replay);
        $client->sendRequest(FakeClient::request(self::URI, $this->prompt('now is 2027-01-01T09:30:00Z')));

        self::assertSame('ok', $this->collector->last()?->assistantContent);
    }

    public function testIdenticalRequestsReplayInRecordedOrder(): void
    {
        $inner = new FakeClient(
            FakeClient::json(FakeClient::openAiReply('first')),
            FakeClient::json(FakeClient::openAiReply('second')),
        );
        $record = $this->client($inner, VcrMode::Record);
        $record->sendRequest(FakeClient::request(self::URI, $this->prompt('retry')));
        $record->sendRequest(FakeClient::request(self::URI, $this->prompt('retry')));

        $replay = $this->client(new FakeClient(), VcrMode::Replay);
        $replay->sendRequest(FakeClient::request(self::URI, $this->prompt('retry')));
        $replay->sendRequest(FakeClient::request(self::URI, $this->prompt('retry')));

        self::assertSame(['first', 'second'], array_map(static fn($i): string => $i->assistantContent, $this->collector->all()));

        $this->expectException(CassetteMissException::class);
        $this->expectExceptionMessage('not occurrence #2');
        $replay->sendRequest(FakeClient::request(self::URI, $this->prompt('retry')));
    }

    public function testReplayMissThrowsWithDiffAgainstClosestInteraction(): void
    {
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('ok'))), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('Refund order #404')));

        $client = $this->client(new FakeClient(), VcrMode::Replay);

        try {
            $client->sendRequest(FakeClient::request(self::URI, $this->prompt('Refund order #405')));
            self::fail('expected miss');
        } catch (CassetteMissException $e) {
            self::assertStringContainsString('Differs from the closest recorded interaction in: messages.0.content', $e->getMessage());
            self::assertStringContainsString('recorded: "Refund order #404"', $e->getMessage());
            self::assertStringContainsString('current:  "Refund order #405"', $e->getMessage());
            self::assertStringContainsString('AI_EVAL_MODE=record vendor/bin/phpunit --filter ' . escapeshellarg('T::t\b'), $e->getMessage());
        }
    }

    public function testReplayMissWithoutCassetteStillThrows(): void
    {
        $this->expectException(CassetteMissException::class);
        $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
    }

    public function testAutoRecordsOnMissThenReplays(): void
    {
        $inner = new FakeClient(FakeClient::json(FakeClient::openAiReply('live')));
        $this->client($inner, VcrMode::Auto)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        $this->client($inner, VcrMode::Auto)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertCount(1, $inner->requests);
        self::assertSame('live', $this->collector->last()?->assistantContent);
    }

    public function testLiveNeverTouchesTheCassette(): void
    {
        $inner = new FakeClient(FakeClient::json(FakeClient::openAiReply('live')));
        $client = $this->client($inner, VcrMode::Live);
        $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertCount(1, $inner->requests);
        self::assertSame('live', $this->collector->last()?->assistantContent);
        self::assertNull($this->storage->load($this->storage->pathFor('T::t')));
    }

    public function testRecordOverwritesExistingEntry(): void
    {
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('old'))), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('new'))), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertCount(1, $this->cassette()->interactions());
        $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        self::assertSame('new', $this->collector->last()?->assistantContent);
    }

    public function testNonLlmTrafficIsRecordedAsRawHttpWithoutTrace(): void
    {
        $inner = new FakeClient(new Response(200, ['Content-Type' => 'text/plain'], 'pong'));
        $this->client($inner, VcrMode::Auto)->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://example.com/ping?token=abc'));

        $entry = $this->cassette()->interactions()[0];
        self::assertSame('raw_http', $entry['kind']);
        self::assertNull($entry['provider']);
        self::assertSame('pong', Payload::get($entry, 'response', 'body'));
        self::assertSame([], $this->collector->all());

        $response = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://example.com/ping?token=other'));
        self::assertSame('pong', (string) $response->getBody());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testLlmUriWithNonJsonBodyFallsBackToRawHttp(): void
    {
        $inner = new FakeClient(new Response(200, [], 'x'));
        $this->client($inner, VcrMode::Record)->sendRequest(new \Nyholm\Psr7\Request('POST', self::URI, [], 'not json'));

        self::assertSame('raw_http', $this->cassette()->interactions()[0]['kind']);
    }

    public function testAppEndpointWithAProviderLikePathStaysRawHttp(): void
    {
        $inner = new FakeClient(FakeClient::json(['reply' => 'a']), FakeClient::json(['reply' => 'b']));
        $client = $this->client($inner, VcrMode::Record);
        foreach (['a', 'b'] as $session) {
            $client->sendRequest(FakeClient::request('https://myapp.test/api/chat', ['session' => $session, 'messages' => []]));
        }

        $entries = $this->cassette()->interactions();
        self::assertSame(['raw_http', 'raw_http'], array_column($entries, 'kind'));
        self::assertNotSame($entries[0]['semantic_hash'], $entries[1]['semantic_hash'], 'requests differing in app fields stay apart');
        self::assertSame([], $this->collector->all());
    }

    public function testErrorResponsesAreRecordedWithoutTrace(): void
    {
        $inner = new FakeClient(FakeClient::json(['error' => 'context too long'], 400));
        $response = $this->client($inner, VcrMode::Record)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->collector->all());
        self::assertArrayNotHasKey('trace', $this->cassette()->interactions()[0]);

        $replayed = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        self::assertSame(400, $replayed->getStatusCode());
    }

    public function testTransientFailuresArePassedThroughButNeverRecorded(): void
    {
        $inner = new FakeClient(
            FakeClient::json(['error' => 'rate limited'], 429),
            FakeClient::json(['error' => 'overloaded'], 529),
            FakeClient::json(FakeClient::openAiReply('finally')),
        );
        $client = $this->client($inner, VcrMode::Auto);

        self::assertSame(429, $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')))->getStatusCode());
        self::assertSame(529, $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')))->getStatusCode());
        self::assertNull($this->storage->load($this->storage->pathFor('T::t')));

        // The app retries; only the good answer lands in the cassette (at the occurrence the retry was made).
        $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        self::assertSame(['finally'], array_map(static fn(array $e): mixed => Payload::get($e, 'response', 'body', 'choices', 0, 'message', 'content'), $this->cassette()->interactions()));
    }

    public function testSeekableRequestBodiesAreRewoundForTheInnerClient(): void
    {
        $inner = new FakeClient(FakeClient::json(FakeClient::openAiReply('ok')));
        $request = FakeClient::request(self::URI, $this->prompt('x'));
        $json = (string) $request->getBody();

        $this->client($inner, VcrMode::Live)->sendRequest($request);

        self::assertSame($json, $inner->requests[0]->getBody()->getContents(), 'the inner client reads the body from the start');
    }

    public function testRedirectsAreNotTraced(): void
    {
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('moved'), 300)), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertSame([], $this->collector->all());
    }

    public function testRawRequestsToDifferentUrisAreKeptApart(): void
    {
        $client = $this->client(new FakeClient(new Response(200, [], 'a'), new Response(200, [], 'b')), VcrMode::Record);
        $client->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://app.test/a'));
        $client->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://app.test/b'));

        self::assertCount(2, array_unique(array_map(Payload::string(...), array_column($this->cassette()->interactions(), 'semantic_hash'))));
    }

    public function testTheCassetteIsReadOncePerClient(): void
    {
        $recorder = $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('a')), FakeClient::json(FakeClient::openAiReply('b'))), VcrMode::Record);
        $recorder->sendRequest(FakeClient::request(self::URI, $this->prompt('a')));
        $recorder->sendRequest(FakeClient::request(self::URI, $this->prompt('b')));

        $replayer = $this->client(new FakeClient(), VcrMode::Replay);
        $replayer->sendRequest(FakeClient::request(self::URI, $this->prompt('a')));
        unlink($this->storage->pathFor('T::t'));
        $replayer->sendRequest(FakeClient::request(self::URI, $this->prompt('b')));

        self::assertSame('b', $this->collector->last()?->assistantContent);
    }

    public function testNonSeekableRequestBodiesReachTheInnerClient(): void
    {
        $json = (string) json_encode($this->prompt('x'));
        $inner = new FakeClient(FakeClient::json(FakeClient::openAiReply('ok')));

        $this->client($inner, VcrMode::Live)->sendRequest(FakeClient::request(self::URI, [])->withBody(new OneShotStream($json)));

        self::assertSame($json, (string) $inner->requests[0]->getBody());
    }

    public function testBinaryResponsesAreStoredAsBase64AndReplayedByteForByte(): void
    {
        $png = "\x89PNG\r\n\x1a\n\x00\xff\xfe";
        $this->client(new FakeClient(new Response(200, ['Content-Type' => 'image/png'], $png)), VcrMode::Record)
            ->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://cdn.test/logo.png'));

        self::assertSame(base64_encode($png), Payload::get($this->cassette()->interactions()[0], 'response', 'body_base64'));
        $replayed = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://cdn.test/logo.png'));
        self::assertSame($png, (string) $replayed->getBody());
        self::assertSame('image/png', $replayed->getHeaderLine('Content-Type'));
    }

    public function testInnerClientExceptionsPropagateAndAreNotRecorded(): void
    {
        $failing = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new class ('network down') extends RuntimeException implements ClientExceptionInterface {};
            }
        };
        $storage = new CassetteStorage($this->tmp);
        $client = new AiVcrClient($failing, $storage, $storage->pathFor('x'), 'x', VcrMode::Record, new AiTraceCollector());

        try {
            $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
            self::fail('expected exception');
        } catch (ClientExceptionInterface $e) {
            self::assertSame('network down', $e->getMessage());
        }
        self::assertNull($storage->load($storage->pathFor('x')));
    }

    public function testResponseHeadersAndRawBodiesAreScrubbed(): void
    {
        $inner = new FakeClient(
            FakeClient::json(FakeClient::openAiReply('ok'), 200, ['Set-Cookie' => '__cf_bm=SESSION']),
            FakeClient::json(['access_token' => 'TOKEN', 'expires_in' => 3600]),
        );
        $client = $this->client($inner, VcrMode::Record);
        $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x'))->withHeader('Cookie', 'sid=COOKIE'));
        $live = $client->sendRequest(new \Nyholm\Psr7\Request(
            'POST',
            'https://user:PASS@auth.test/oauth/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'grant_type=client_credentials&client_id=app&client_secret=CLIENT_SECRET',
        ));

        self::assertSame('TOKEN', Payload::get(json_decode((string) $live->getBody(), true), 'access_token'), 'live caller sees the real body');
        $json = $this->cassette()->toJson();
        foreach (['SESSION', 'COOKIE', 'PASS', 'CLIENT_SECRET', 'TOKEN'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $raw = $this->cassette()->interactions()[1];
        self::assertSame('grant_type=client_credentials&client_id=app&client_secret=[REDACTED]', Payload::get($raw, 'request', 'normalized_payload', 'body'));
        self::assertSame(3600, Payload::get($raw, 'response', 'body', 'expires_in'));

        // The scrubbed form still replays: secrets are redacted before hashing, so a rotated secret still matches.
        $replayed = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(new \Nyholm\Psr7\Request(
            'POST',
            'https://user:OTHER@auth.test/oauth/token',
            [],
            'grant_type=client_credentials&client_id=app&client_secret=ROTATED',
        ));
        self::assertSame('[REDACTED]', Payload::get(json_decode((string) $replayed->getBody(), true), 'access_token'));
    }

    public function testUsedEntriesAreReportedForPruning(): void
    {
        TestContextRegistry::reset();
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('ok'))), VcrMode::Auto)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        $used = TestContextRegistry::used();
        TestContextRegistry::reset();
        self::assertSame([$this->storage->pathFor('T::t')], array_keys($used));
        self::assertCount(1, $used[$this->storage->pathFor('T::t')]);
    }

    public function testEmptyJsonObjectsSurviveReplay(): void
    {
        $reply = '{"model":"gpt-x","output":[{"type":"message","content":[{"type":"output_text","text":"hi"}]}],"metadata":{},"tools":[],"usage":{"input_tokens":2,"output_tokens":1}}';
        $uri = 'https://api.openai.com/v1/responses';
        $this->client(new FakeClient(new Response(200, ['Content-Type' => 'application/json'], $reply)), VcrMode::Record)
            ->sendRequest(FakeClient::request($uri, ['model' => 'gpt-x', 'input' => 'hi']));

        $replayed = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request($uri, ['model' => 'gpt-x', 'input' => 'hi']));

        self::assertSame($reply, (string) $replayed->getBody());
        self::assertSame(['hi', 3], [$this->collector->last()?->assistantContent, $this->collector->last()?->totalTokens]);

        // Bodies without that ambiguity are still stored decoded, so cassettes stay readable.
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('ok'))), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('readable')));
        self::assertIsArray(Payload::get($this->cassette()->interactions()[1], 'response', 'body'));
    }

    public function testRecordedEntryKeepsTheFullTraceAndDropsTransportHeaders(): void
    {
        $reply = FakeClient::json(FakeClient::openAiReply('ok', [['lookup', ['id' => 1]]], 7, 3), 200, ['content-LENGTH' => '99', 'Transfer-Encoding' => 'chunked', 'X-Request-Id' => 'r1']);
        $slow = new class (new FakeClient($reply)) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private readonly FakeClient $inner) {}

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                usleep(2_345);

                return $this->inner->sendRequest($request);
            }
        };
        $this->collector = new AiTraceCollector();
        $this->storage = new CassetteStorage($this->tmp);
        (new AiVcrClient($slow, $this->storage, $this->storage->pathFor('T::t'), 'App\\T::t', VcrMode::Record, $this->collector))
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        $entry = $this->cassette()->interactions()[0];
        self::assertSame(['Content-Type', 'X-Request-Id'], array_keys(Payload::map(Payload::get($entry, 'response', 'headers'))));
        $trace = Payload::map($entry['trace'] ?? null);
        self::assertSame([7, 3, 0, [['name' => 'lookup', 'arguments' => ['id' => 1]]]], [$trace['prompt_tokens'], $trace['completion_tokens'], $trace['reasoning_tokens'], $trace['tool_calls']]);
        self::assertSame(round($this->collector->last()->latencyMs ?? 0.0, 1), Payload::float($trace['latency_ms']), 'latency is stored to 0.1 ms');
    }

    public function testRawRequestsAreKeptApartByMethodAnd500IsNeverRecorded(): void
    {
        $inner = new FakeClient(new Response(200, [], 'got'), new Response(200, [], 'deleted'), new Response(500, [], 'boom'));
        $client = $this->client($inner, VcrMode::Record);
        $client->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://api.test/item/1'));
        $client->sendRequest(new \Nyholm\Psr7\Request('DELETE', 'https://api.test/item/1'));
        $client->sendRequest(new \Nyholm\Psr7\Request('POST', 'https://api.test/fail'));

        self::assertSame(['got', 'deleted'], array_map(static fn(array $e): mixed => Payload::get($e, 'response', 'body'), $this->cassette()->interactions()));

        // Replayed in the opposite order, each method still gets its own answer.
        $replay = $this->client(new FakeClient(), VcrMode::Replay);
        self::assertSame('deleted', (string) $replay->sendRequest(new \Nyholm\Psr7\Request('DELETE', 'https://api.test/item/1'))->getBody());
        self::assertSame('got', (string) $replay->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://api.test/item/1'))->getBody());
    }

    public function testLiveAndReplayedCallsAreCountedApartAndMarkedUsed(): void
    {
        TestContextRegistry::reset();
        TestContextRegistry::startTest('T::t');
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('ok'))), VcrMode::Record)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        $recorded = TestContextRegistry::used();
        $live = TestContextRegistry::tests()['T::t'];

        TestContextRegistry::reset();
        TestContextRegistry::startTest('T::t');
        $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        $metrics = TestContextRegistry::tests()['T::t'];
        $replayed = TestContextRegistry::used();
        TestContextRegistry::reset();

        self::assertCount(1, $recorded[$this->storage->pathFor('T::t')] ?? [], 'recording marks the entry as used');
        self::assertSame($recorded, $replayed, 'replaying marks the same entry');
        self::assertSame([0, 1], [$live['vcrHits'], $live['liveCalls']], 'a recorded call is a live call');
        self::assertSame([1, 0], [$metrics['vcrHits'], $metrics['liveCalls']], 'a replayed call is a VCR hit');
    }

    public function testJudgeReplayIsMarkedUsedAndMissesShowAPromptDiff(): void
    {
        $payload = static fn(string $q): array => ['model' => 'm', 'messages' => [['role' => 'user', 'content' => $q]]];
        $this->client(new FakeClient(), VcrMode::Record)->judgeVerdict($payload('rubric A'), static fn(): JudgeVerdict => new JudgeVerdict(0.5, 'r'));

        TestContextRegistry::reset();
        $this->client(new FakeClient(), VcrMode::Replay)->judgeVerdict($payload('rubric A'), static fn(): JudgeVerdict => new JudgeVerdict(0.0, 'unused'));
        self::assertCount(1, TestContextRegistry::used()[$this->storage->pathFor('T::t')] ?? []);
        TestContextRegistry::reset();

        try {
            $this->client(new FakeClient(), VcrMode::Replay)->judgeVerdict($payload('rubric B'), static fn(): JudgeVerdict => new JudgeVerdict(0.0, ''));
            self::fail('expected a miss');
        } catch (CassetteMissException $e) {
            self::assertStringContainsString('recorded: "rubric A"', $e->getMessage());
        }
    }

    public function testHandWrittenEntriesWithoutStatusReplayAs200(): void
    {
        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('ok'))), VcrMode::Record)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        $path = $this->storage->pathFor('T::t');
        $json = Payload::map(json_decode((string) file_get_contents($path), true));
        $entries = Payload::maps($json['interactions'] ?? null);
        $entries[0]['response'] = array_diff_key(Payload::map($entries[0]['response'] ?? null), ['status' => true]);
        file_put_contents($path, json_encode(['interactions' => $entries] + $json));

        self::assertSame(200, $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')))->getStatusCode());
    }

    public function testRejectedCredentialsArePassedThroughButNeverRecorded(): void
    {
        $inner = new FakeClient(
            FakeClient::json(['error' => 'invalid x-api-key'], 401),
            FakeClient::json(['error' => 'forbidden'], 403),
            FakeClient::json(['error' => 'proxy auth'], 407),
            FakeClient::json(['error' => 'bad request'], 400),
        );
        $client = $this->client($inner, VcrMode::Auto);

        foreach ([401, 403, 407, 400] as $status) {
            self::assertSame($status, $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')))->getStatusCode());
        }
        self::assertSame([400], array_column(array_column($this->cassette()->interactions(), 'response'), 'status'), 'only the 400 is a real answer');
    }

    public function testRawRequestsReplayDespiteTimestampsInTheirBody(): void
    {
        $this->client(new FakeClient(new Response(200, [], 'hit')), VcrMode::Record)
            ->sendRequest(FakeClient::request('https://app.test/search', ['q' => 'x', 'at' => '2025-01-01T00:00:00Z']));

        $replayed = $this->client(new FakeClient(), VcrMode::Replay)
            ->sendRequest(FakeClient::request('https://app.test/search', ['q' => 'x', 'at' => '2026-10-22T12:00:00Z']));

        self::assertSame('hit', (string) $replayed->getBody());
    }

    public function testLatencyIncludesReadingTheBody(): void
    {
        $body = new class (json_encode(FakeClient::openAiReply('slow'), JSON_THROW_ON_ERROR)) extends OneShotStream {
            public function getContents(): string
            {
                usleep(50_000);

                return parent::getContents();
            }
        };
        $this->client(new FakeClient(FakeClient::json([])->withBody($body)), VcrMode::Live)
            ->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertGreaterThanOrEqual(25.0, $this->collector->last()?->latencyMs, "Windows sleeps are coarse; without the fix this is ~0");
    }

    public function testAzureOpenAiDeploymentsAreTracedWithTheDeploymentAsModel(): void
    {
        $client = $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('hi'))), VcrMode::Record);
        $client->sendRequest(FakeClient::request(
            'https://acme.openai.azure.com/openai/deployments/my-gpt/chat/completions?api-version=2024-10-21',
            ['messages' => [['role' => 'user', 'content' => 'x']]],
        ));

        self::assertSame('agent_call', $this->cassette()->interactions()[0]['kind']);
        self::assertSame('my-gpt', $this->cassette()->interactions()[0]['model']);
        self::assertSame('hi', $this->collector->last()?->assistantContent);
    }

    public function testARetriedFailureDoesNotShiftTheRecordingAwayFromTheFirstAttempt(): void
    {
        $record = $this->client(new FakeClient(
            FakeClient::json(['error' => 'rate limited'], 429),
            FakeClient::json(FakeClient::openAiReply('after retry')),
        ), VcrMode::Auto);
        $record->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        $record->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        // On replay nothing fails, so the app sends the request once.
        $replayed = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertStringContainsString('after retry', (string) $replayed->getBody());
    }

    public function testAFailedTransportDoesNotUseUpAnOccurrence(): void
    {
        $inner = new class implements \Psr\Http\Client\ClientInterface {
            public int $calls = 0;

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('connection reset');
                }

                return FakeClient::json(FakeClient::openAiReply('second try'));
            }
        };
        $this->collector = new AiTraceCollector();
        $this->storage = new CassetteStorage($this->tmp);
        $client = new AiVcrClient($inner, $this->storage, $this->storage->pathFor('T::t'), 'App\\T::t', VcrMode::Auto, $this->collector);
        try {
            $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));
        } catch (RuntimeException) {
        }
        $client->sendRequest(FakeClient::request(self::URI, $this->prompt('x')));

        self::assertSame([0], array_column($this->cassette()->interactions(), 'occurrence_index'));
    }

    public function testAppEndpointWithAModelFieldButNoPromptStaysRawHttp(): void
    {
        $request = static fn(int $rating) => FakeClient::request('https://app.test/api/survey/responses', ['model' => 'Golf', 'rating' => $rating]);
        $this->client(new FakeClient(new Response(200, [], 'saved 5')), VcrMode::Record)->sendRequest($request(5));

        $replayed = $this->client(new FakeClient(new Response(200, [], 'saved 1')), VcrMode::Auto)->sendRequest($request(1));

        self::assertSame('saved 1', (string) $replayed->getBody(), 'a different body is a different request');
        self::assertSame(['raw_http', 'raw_http'], array_column($this->cassette()->interactions(), 'kind'));
    }
}
