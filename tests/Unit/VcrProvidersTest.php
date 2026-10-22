<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\VcrTestTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Trace\Parser\StreamingPayloadParserInterface;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Provider-specific requests and streams through the VCR: URL details, SSE, NDJSON, JSON arrays, AWS event streams.
 */
final class VcrProvidersTest extends TestCase
{
    use VcrTestTrait;

    private const URI = 'https://api.openai.com/v1/chat/completions?key=SECRET';

    public function testBedrockConverseAndClaudeOnVertexAreTracedWithModelFromTheUrl(): void
    {
        $bedrock = 'https://bedrock-runtime.us-east-1.amazonaws.com/model/anthropic.claude-haiku-5-5-v1%3A0/converse';
        $vertex = 'https://us-east5-aiplatform.googleapis.com/v1/projects/p/locations/us-east5/publishers/anthropic/models/claude-haiku-5-5:rawPredict';
        $inner = new FakeClient(
            FakeClient::json([
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Checking.'], ['toolUse' => ['toolUseId' => 't1', 'name' => 'lookup', 'input' => ['id' => 7]]]]]],
                'usage' => ['inputTokens' => 20, 'outputTokens' => 6, 'cacheReadInputTokens' => 100],
            ]),
            FakeClient::json(['model' => 'claude-haiku-5-5', 'content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => ['input_tokens' => 4, 'output_tokens' => 2]]),
        );
        $client = $this->client($inner, VcrMode::Record);
        $client->sendRequest(FakeClient::request($bedrock, ['messages' => [['role' => 'user', 'content' => [['text' => 'find 7']]]]])->withHeader('X-Amz-Security-Token', 'STS-SECRET'));
        $client->sendRequest(FakeClient::request($vertex, ['anthropic_version' => 'vertex-2023-10-16', 'messages' => [['role' => 'user', 'content' => 'hi']]]));

        [$b, $v] = $this->collector->all();
        self::assertSame(['bedrock', 'anthropic.claude-haiku-5-5-v1:0', 120, 6, 'Checking.', 'lookup'], [$b->provider, $b->model, $b->promptTokens, $b->completionTokens, $b->assistantContent, $b->toolCalls[0]->name ?? null]);
        self::assertSame(['anthropic', 'claude-haiku-5-5', 'Hi'], [$v->provider, $v->model, $v->assistantContent]);
        self::assertStringNotContainsString('STS-SECRET', $this->cassette()->toJson());
    }

    public function testStreamingResponseIsCondensedAndReplayedAsSse(): void
    {
        $sse = "data: {\"model\":\"gpt-6-luna\",\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
            . "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":4,\"completion_tokens\":2}}\n\n"
            . "data: [DONE]\n\n";
        $inner = new FakeClient(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));
        $live = $this->client($inner, VcrMode::Record)->sendRequest(FakeClient::request(self::URI, $this->prompt('stream') + ['stream' => true]));

        self::assertSame($sse, (string) $live->getBody());
        $entry = $this->cassette()->interactions()[0];
        self::assertTrue(Payload::get($entry, 'response', 'is_stream'));
        self::assertCount(2, Payload::maps(Payload::get($entry, 'response', 'body')));
        self::assertSame(6, $this->collector->last()?->totalTokens);

        $replay = $this->client(new FakeClient(), VcrMode::Replay);
        $replayed = $replay->sendRequest(FakeClient::request(self::URI, $this->prompt('stream') + ['stream' => true]));
        self::assertSame($sse, (string) $replayed->getBody());
        self::assertSame('Hi', $this->collector->last()?->assistantContent);

        // A non-streaming request must not be answered with the recorded SSE body.
        $this->expectException(CassetteMissException::class);
        $replay->sendRequest(FakeClient::request(self::URI, $this->prompt('stream')));
    }

    public function testGeminiModelFromUriKeepsHashesApart(): void
    {
        $body = ['contents' => [['parts' => [['text' => 'hi']]]]];
        $reply = ['candidates' => [['content' => ['parts' => [['text' => 'yo']]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]];
        $inner = new FakeClient(FakeClient::json($reply), FakeClient::json($reply));
        $client = $this->client($inner, VcrMode::Record);
        $client->sendRequest(FakeClient::request('https://g.test/v1beta/models/gemini-a:generateContent?key=k', $body));
        $client->sendRequest(FakeClient::request('https://g.test/v1beta/models/gemini-b:generateContent?key=k', $body));

        $hashes = array_map(static fn(array $e): string => Payload::string($e['semantic_hash']), $this->cassette()->interactions());
        self::assertCount(2, array_unique($hashes));
    }

    public function testOllamaNdjsonStreamIsCondensedAndReplayed(): void
    {
        $ndjson = '{"model":"gemma4:e4b","message":{"role":"assistant","content":"Hel"},"done":false}' . "\n"
            . '{"model":"gemma4:e4b","message":{"role":"assistant","content":"lo"},"done":false}' . "\n"
            . '{"model":"gemma4:e4b","message":{"role":"assistant","content":""},"done":true,"prompt_eval_count":7,"eval_count":2}' . "\n";
        $uri = 'http://localhost:11434/api/chat';
        $inner = new FakeClient(new Response(200, ['Content-Type' => 'application/x-ndjson'], $ndjson));
        $this->client($inner, VcrMode::Record)->sendRequest(FakeClient::request($uri, ['model' => 'gemma4:e4b', 'messages' => [['role' => 'user', 'content' => 'hi']]]));

        self::assertSame(['Hello', 9], [$this->collector->last()?->assistantContent, $this->collector->last()?->totalTokens]);
        self::assertCount(3, Payload::maps(Payload::get($this->cassette()->interactions()[0], 'response', 'body')));

        $replayed = $this->client(new FakeClient(), VcrMode::Replay)
            ->sendRequest(FakeClient::request($uri, ['model' => 'gemma4:e4b', 'messages' => [['role' => 'user', 'content' => 'hi']]]));
        self::assertSame($ndjson, (string) $replayed->getBody());
        self::assertSame('Hello', $this->collector->last()?->assistantContent);
    }

    public function testOpenAiResponsesApiIsTraced(): void
    {
        $reply = [
            'model' => 'gpt-6-sol',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'function_call', 'name' => 'issue_refund', 'arguments' => '{"order_id":404}', 'call_id' => 'c1'],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Refunded.']]],
            ],
            'usage' => ['input_tokens' => 30, 'output_tokens' => 12, 'output_tokens_details' => ['reasoning_tokens' => 8]],
        ];
        $this->client(new FakeClient(FakeClient::json($reply)), VcrMode::Record)
            ->sendRequest(FakeClient::request('https://api.openai.com/v1/responses', ['model' => 'gpt-6-sol', 'input' => 'Refund #404', 'instructions' => 'be brief']));

        $entry = $this->cassette()->interactions()[0];
        self::assertSame(['agent_call', 'openai-responses'], [$entry['kind'], $entry['provider']]);
        self::assertSame('be brief', Payload::get($entry, 'request', 'normalized_payload', 'instructions'));
        $trace = $this->collector->last();
        self::assertSame(['Refunded.', 42, 8, 'issue_refund'], [$trace?->assistantContent, $trace?->totalTokens, $trace?->reasoningTokens, $trace?->toolCalls[0]->name]);
    }

    public function testGeminiJsonArrayStreamIsMergedForTheTrace(): void
    {
        $chunks = [
            ['candidates' => [['content' => ['parts' => [['text' => 'Hello, ']]]]], 'modelVersion' => 'gemini-x'],
            ['candidates' => [['content' => ['parts' => [['text' => 'friend.']]]]], 'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 3]],
        ];
        $uri = 'https://g.test/v1beta/models/gemini-x:streamGenerateContent';
        $body = ['contents' => [['parts' => [['text' => 'hi']]]]];
        $this->client(new FakeClient(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($chunks))), VcrMode::Record)
            ->sendRequest(FakeClient::request($uri, $body));

        self::assertSame(['Hello, friend.', 10, 'gemini-x'], [$this->collector->last()?->assistantContent, $this->collector->last()?->totalTokens, $this->collector->last()?->model]);

        $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request($uri, $body));
        self::assertSame('Hello, friend.', $this->collector->last()?->assistantContent);
    }

    public function testNonLlmStreamsAreRecordedAndReplayedVerbatim(): void
    {
        $sse = "event: ping\nid: 7\ndata: hello world\n\n";
        $this->client(new FakeClient(new Response(200, ['Content-Type' => 'text/event-stream'], $sse)), VcrMode::Record)
            ->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://events.test/feed'));

        $replayed = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://events.test/feed'));

        self::assertSame($sse, (string) $replayed->getBody());
        self::assertFalse(Payload::get($this->cassette()->interactions()[0], 'response', 'is_stream'));
    }

    public function testBedrockConverseStreamIsDecodedTracedAndReplayedAsEventStream(): void
    {
        $uri = 'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-micro-v1%3A0/converse-stream';
        $events = [
            ['messageStart', ['role' => 'assistant', 'p' => 'abcd']],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Looking '], 'p' => 'ab']],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'it up.']]],
            ['contentBlockStart', ['contentBlockIndex' => 1, 'start' => ['toolUse' => ['toolUseId' => 't1', 'name' => 'lookup']]]],
            ['contentBlockDelta', ['contentBlockIndex' => 1, 'delta' => ['toolUse' => ['input' => '{"id":']]]],
            ['contentBlockDelta', ['contentBlockIndex' => 1, 'delta' => ['toolUse' => ['input' => '7}']]]],
            ['messageStop', ['stopReason' => 'tool_use']],
            ['metadata', ['usage' => ['inputTokens' => 12, 'outputTokens' => 9, 'totalTokens' => 21], 'metrics' => ['latencyMs' => 300]]],
        ];
        $binary = implode('', array_map(static fn(array $e): string => self::awsEventFrame($e[0], $e[1]), $events));
        $body = ['messages' => [['role' => 'user', 'content' => [['text' => 'find 7']]]]];

        $this->client(new FakeClient(new Response(200, ['Content-Type' => 'application/vnd.amazon.eventstream'], $binary)), VcrMode::Record)
            ->sendRequest(FakeClient::request($uri, $body));

        $live = $this->collector->last();
        self::assertSame(['bedrock', 'amazon.nova-micro-v1:0', 21, 'Looking it up.', 'lookup', ['id' => 7]], [$live?->provider, $live?->model, $live?->totalTokens, $live?->assistantContent, $live?->toolCalls[0]->name, $live?->toolCalls[0]->arguments]);
        self::assertCount(8, Payload::maps(Payload::get($this->cassette()->interactions()[0], 'response', 'body')), 'stored as readable JSON chunks');
        self::assertTrue(Payload::get($this->cassette()->interactions()[0], 'request', 'normalized_payload', 'stream'), 'converse-stream never shares a hash with converse');

        $replayed = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request($uri, $body));
        self::assertSame($binary, (string) $replayed->getBody(), 'replay re-renders the identical binary frames');
        self::assertSame('Looking it up.', $this->collector->last()?->assistantContent);
    }

    /**
     * One AWS event-stream frame, built independently of StreamRebuilder.
     *
     * @param array<string, mixed> $payload
     */
    private static function awsEventFrame(string $type, array $payload, string $messageType = 'event'): string
    {
        $headers = '';
        $typeHeader = $messageType === 'exception' ? ':exception-type' : ':event-type';
        foreach ([$typeHeader => $type, ':content-type' => 'application/json', ':message-type' => $messageType] as $name => $value) {
            $headers .= chr(strlen($name)) . $name . "\x07" . pack('n', strlen($value)) . $value;
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $prelude = pack('NN', 16 + strlen($headers) + strlen($json), strlen($headers));
        $message = $prelude . pack('N', crc32($prelude)) . $headers . $json;

        return $message . pack('N', crc32($message));
    }

    public function testCustomParsersCanMergeAndRenderTheirOwnStreams(): void
    {
        $parser = new class implements StreamingPayloadParserInterface {
            public function name(): string
            {
                return 'acme';
            }

            public function supports(string $uri): bool
            {
                return str_ends_with($uri, '/acme/chat');
            }

            public function requestPayload(string $uri, array $payload): array
            {
                return $payload;
            }

            public function parse(array $request, array $response, float $latencyMs): AiInteraction
            {
                return new AiInteraction('acme', 'a1', 0, Payload::int($response['tokens'] ?? null), 0, $latencyMs, Payload::string($response['text'] ?? null));
            }

            public function mergeStream(array $chunks): array
            {
                return ['text' => implode('', array_map(static fn(array $c): string => Payload::string($c['delta'] ?? null), $chunks)), 'tokens' => Payload::get(end($chunks), 'tokens')];
            }

            public function renderStream(array $chunks): string
            {
                return implode('', array_map(static fn(array $c): string => 'data: ' . json_encode($c) . "\n\n", $chunks)) . "data: END\n\n";
            }
        };
        $sse = "data: {\"delta\":\"Hel\"}\n\ndata: {\"delta\":\"lo\",\"tokens\":7}\n\ndata: END\n\n";
        $storage = new CassetteStorage($this->tmp);
        $client = static fn(FakeClient $inner, VcrMode $mode, AiTraceCollector $collector): AiVcrClient => new AiVcrClient(
            $inner,
            $storage,
            $storage->pathFor('T::t'),
            'T::t',
            $mode,
            $collector,
            parsers: [$parser],
        );
        $request = FakeClient::request('https://acme.test/acme/chat', ['model' => 'a1', 'messages' => [], 'stream' => true]);

        $recording = new AiTraceCollector();
        $client(new FakeClient(new Response(200, ['Content-Type' => 'text/event-stream'], $sse)), VcrMode::Record, $recording)->sendRequest($request);
        $replaying = new AiTraceCollector();
        $replayed = $client(new FakeClient(), VcrMode::Replay, $replaying)->sendRequest($request);

        self::assertSame($sse, (string) $replayed->getBody());
        foreach ([$recording, $replaying] as $collector) {
            self::assertSame('Hello', $collector->last()?->assistantContent);
            self::assertSame(7, $collector->last()->completionTokens);
        }
    }

    public function testErrorsInsideA200StreamArePassedThroughButNeverRecorded(): void
    {
        $prompt = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']], 'stream' => true];
        $streams = [
            'anthropic' => ['https://api.anthropic.com/v1/messages', 'text/event-stream', "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"model\":\"c\"}}\n\nevent: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\"}}\n\n"],
            'openai' => [self::URI, 'text/event-stream', "data: {\"error\":{\"type\":\"server_error\"}}\n\n"],
            'ollama' => ['http://localhost:11434/api/chat', 'application/x-ndjson', "{\"error\":\"model is loading\"}\n"],
            'bedrock' => [
                'https://bedrock-runtime.us-east-1.amazonaws.com/model/m/converse-stream',
                'application/vnd.amazon.eventstream',
                self::awsEventFrame('messageStart', ['role' => 'assistant']) . self::awsEventFrame('throttlingException', ['message' => 'slow down'], 'exception'),
            ],
        ];
        foreach ($streams as $name => [$uri, $type, $body]) {
            $client = $this->client(new FakeClient(new Response(200, ['Content-Type' => $type], $body)), VcrMode::Auto);
            self::assertSame($body, (string) $client->sendRequest(FakeClient::request($uri, $prompt))->getBody(), $name);
            self::assertNull($this->storage->load($this->storage->pathFor('T::t')), "$name: a failed stream is not recorded");
        }
    }
}
