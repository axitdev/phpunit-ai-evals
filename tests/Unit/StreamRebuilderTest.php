<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Trace\Parser\AnthropicPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\GeminiPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OllamaPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OpenAiPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OpenAiResponsesPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;
use Axitdev\PhpUnitAiEvals\Vcr\StreamRebuilder;
use PHPUnit\Framework\TestCase;

final class StreamRebuilderTest extends TestCase
{
    private const OPENAI = <<<'SSE'
        data: {"model":"gpt-6-luna","choices":[{"delta":{"role":"assistant","content":"Hel"}}]}

        data: {"choices":[{"delta":{"content":"lo"}}]}

        data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"name":"issue_","arguments":"{\"order"}}]}}]}

        data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"name":"refund","arguments":"_id\":404}"}}]}}]}

        data: {"choices":[],"usage":{"prompt_tokens":9,"completion_tokens":4}}

        data: [DONE]


        SSE;

    public function testParseSkipsDoneAndJunk(): void
    {
        $chunks = (new StreamRebuilder())->parse(": keepalive\r\n\r\ndata: {\"a\":1}\r\n\r\ndata: not-json\n\ndata: [DONE]\n\n");

        self::assertSame([['a' => 1]], $chunks);
    }

    public function testParseJoinsMultilineData(): void
    {
        self::assertSame([['a' => 1]], (new StreamRebuilder())->parse("data: {\"a\":\ndata: 1}\n\n"));
    }

    public function testOpenAiMergeFeedsParserAndRenderReplaysValidStream(): void
    {
        $sse = new StreamRebuilder();
        $chunks = $sse->parse(self::OPENAI);
        $i = (new OpenAiPayloadParser())->parse([], $sse->merge($chunks, 'openai'), 1.0);

        self::assertSame('Hello', $i->assistantContent);
        self::assertSame(13, $i->totalTokens);
        self::assertEquals([new ToolCall('issue_refund', ['order_id' => 404])], $i->toolCalls);

        $rendered = $sse->render($chunks, 'openai');
        self::assertStringStartsWith('data: {"model":"gpt-6-luna"', $rendered);
        self::assertStringEndsWith("\n\ndata: [DONE]\n\n", $rendered);
        self::assertSame($chunks, $sse->parse($rendered));
    }

    public function testAnthropicMergeAndRenderWithEventLines(): void
    {
        $sse = new StreamRebuilder();
        $chunks = [
            ['type' => 'message_start', 'message' => ['model' => 'claude-x', 'usage' => ['input_tokens' => 20, 'cache_read_input_tokens' => 100, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'there']],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'name' => 'issue_refund']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"order_id"']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ':404}']],
            ['type' => 'message_delta', 'usage' => ['output_tokens' => 15]],
        ];

        $i = (new AnthropicPayloadParser())->parse([], $sse->merge($chunks, 'anthropic'), 0.0);

        self::assertSame('Hi there', $i->assistantContent);
        self::assertSame([120, 15, 'claude-x'], [$i->promptTokens, $i->completionTokens, $i->model]);
        self::assertEquals([new ToolCall('issue_refund', ['order_id' => 404])], $i->toolCalls);

        $rendered = $sse->render($chunks, 'anthropic');
        self::assertStringStartsWith("event: message_start\ndata: {", $rendered);
        self::assertStringNotContainsString('[DONE]', $rendered);
        self::assertSame($chunks, $sse->parse($rendered));
    }

    public function testGeminiMerge(): void
    {
        $sse = new StreamRebuilder();
        $chunks = [
            ['candidates' => [['content' => ['parts' => [['text' => 'A']]]]], 'modelVersion' => 'g-2'],
            ['candidates' => [['content' => ['parts' => [['text' => 'B'], ['functionCall' => ['name' => 'f', 'args' => ['x' => 1]]]]]]],
                'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2]],
        ];

        $i = (new GeminiPayloadParser())->parse([], $sse->merge($chunks, 'gemini'), 0.0);

        self::assertSame(['AB', 'g-2', 5], [$i->assistantContent, $i->model, $i->totalTokens]);
        self::assertEquals([new ToolCall('f', ['x' => 1])], $i->toolCalls);
        self::assertStringNotContainsString('[DONE]', $sse->render($chunks, 'gemini'));
    }

    public function testUnknownProviderMergesToLastChunk(): void
    {
        $sse = new StreamRebuilder();

        self::assertSame(['b' => 2], $sse->merge([['a' => 1], ['b' => 2]], 'other'));
        self::assertSame([], $sse->merge([], 'other'));
    }

    public function testMalformedEventStreamFramesAreSkippedWithoutFailing(): void
    {
        $streams = new StreamRebuilder();
        $good = $streams->render([['_event' => 'metadata', 'usage' => ['inputTokens' => 1]]], 'bedrock');
        $type = 'application/vnd.amazon.eventstream';

        self::assertSame([], $streams->parse('not a frame', $type));
        self::assertSame([['_event' => 'metadata', 'usage' => ['inputTokens' => 1]]], $streams->parse($good . substr($good, 0, 20), $type), 'a truncated trailing frame is dropped');
        self::assertCount(1, $streams->parse($good . substr($good, 0, -4), $type), 'so is one missing only its CRC');

        // A header of another type (here 6, bytes) ends the header block; the payload is still read.
        $headers = chr(5) . ':blob' . chr(6) . pack('n', 2) . 'xx';
        $payload = '{"ok":true}';
        $prelude = pack('NN', 16 + strlen($headers) + strlen($payload), strlen($headers));
        $frame = $prelude . pack('N', crc32($prelude)) . $headers . $payload;
        self::assertSame([['_event' => '', 'ok' => true]], $streams->parse($frame . pack('N', crc32($frame)), $type));
    }

    public function testDetectsStreamContentTypes(): void
    {
        self::assertTrue(StreamRebuilder::isStream('text/event-stream; charset=utf-8'));
        self::assertTrue(StreamRebuilder::isStream('application/x-ndjson'));
        self::assertFalse(StreamRebuilder::isStream('application/json'));
    }

    public function testOllamaNdjsonGenerateMergeAndRender(): void
    {
        $streams = new StreamRebuilder();
        $ndjson = "{\"model\":\"m\",\"response\":\"A\",\"done\":false}\n{\"model\":\"m\",\"response\":\"B\",\"done\":true,\"prompt_eval_count\":3,\"eval_count\":2}\n";
        $chunks = $streams->parse($ndjson);

        $i = (new OllamaPayloadParser())->parse([], $streams->merge($chunks, 'ollama'), 0.0);

        self::assertSame(['AB', 5], [$i->assistantContent, $i->totalTokens]);
        self::assertSame($ndjson, $streams->render($chunks, 'ollama'));
        self::assertSame([], $streams->merge([], 'ollama'));
    }

    public function testOpenAiResponsesStreamTakesTheCompletedResponse(): void
    {
        $streams = new StreamRebuilder();
        $chunks = [
            ['type' => 'response.created', 'response' => ['output' => []]],
            ['type' => 'response.output_text.delta', 'delta' => 'Hi'],
            ['type' => 'response.completed', 'response' => [
                'model' => 'gpt-6-sol',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi']]]],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
            ]],
        ];

        $i = (new OpenAiResponsesPayloadParser())->parse([], $streams->merge($chunks, 'openai-responses'), 0.0);
        $rendered = $streams->render($chunks, 'openai-responses');

        self::assertSame(['Hi', 4], [$i->assistantContent, $i->totalTokens]);
        self::assertStringStartsWith("event: response.created\ndata: {", $rendered);
        self::assertStringNotContainsString('[DONE]', $rendered);
        self::assertSame($chunks, $streams->parse($rendered));
    }

    public function testParsingToleratesCaseLeadingBlankLinesAndMissingSpace(): void
    {
        $streams = new StreamRebuilder();

        self::assertTrue(StreamRebuilder::isStream('Text/Event-Stream'));
        self::assertSame([['a' => 1], ['b' => 2]], $streams->parse("\ndata:{\"a\":1}\n\ndata: {\"b\":2}\n\n"));
        self::assertSame([['_event' => 'm']], $streams->parse($streams->render([['_event' => 'm']], 'bedrock'), 'Application/VND.Amazon.EventStream'));
    }

    public function testRenderKeepsUnicodeAndSlashesAndOnlyTypesEventsForTypedProviders(): void
    {
        $streams = new StreamRebuilder();
        $chunk = ['type' => 'x', 'text' => 'café/au-lait'];

        self::assertSame("data: {\"type\":\"x\",\"text\":\"café/au-lait\"}\n\ndata: [DONE]\n\n", $streams->render([$chunk], 'openai'));
        self::assertStringStartsWith("event: x\ndata: {\"type\":\"x\",\"text\":\"café/au-lait\"}", $streams->render([$chunk], 'anthropic'));
        self::assertStringContainsString('café/au-lait', $streams->render([$chunk], 'bedrock'));
    }

    public function testEventStreamHeadersAreReadInAnyOrderAcrossFrames(): void
    {
        $raw = self::frame([':message-type' => 'event', ':content-type' => 'application/json', ':event-type' => 'messageStart'], '{"role":"assistant"}')
            . self::frame([':content-type' => 'application/json', ':event-type' => 'metadata'], '{"usage":{"inputTokens":3}}');

        self::assertSame(
            [['_event' => 'messageStart', 'role' => 'assistant'], ['_event' => 'metadata', 'usage' => ['inputTokens' => 3]]],
            (new StreamRebuilder())->parse($raw, 'application/vnd.amazon.eventstream'),
        );
    }

    public function testOpenAiMergeKeepsTheModelOrdersToolCallsAndUsesPositionWithoutIndex(): void
    {
        $merged = (new StreamRebuilder())->merge([
            ['model' => 'gpt-6-luna', 'choices' => [['delta' => ['tool_calls' => [['index' => 1, 'function' => ['name' => 'second', 'arguments' => '{}']]]]]]],
            ['model' => '', 'choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['name' => 'first', 'arguments' => '{}']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['function' => ['name' => 'no-index', 'arguments' => '']]]]]]],
        ], 'openai');

        self::assertSame('gpt-6-luna', $merged['model']);
        self::assertSame(
            [['function' => ['name' => 'firstno-index', 'arguments' => '{}']], ['function' => ['name' => 'second', 'arguments' => '{}']]],
            \Axitdev\PhpUnitAiEvals\Support\Payload::get($merged, 'choices', 0, 'message', 'tool_calls'),
            'a call without index lands at its position in the delta (0) and indexes are ordered',
        );
    }

    public function testAnthropicMergeDefaultsToTextBlocksKeepsUsageAndOrdersBlocks(): void
    {
        $merged = (new StreamRebuilder())->merge([
            ['type' => 'message_start', 'message' => ['model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 9, 'output_tokens' => 1]]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'second']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'first ']],
            ['type' => 'message_delta', 'usage' => ['input_tokens' => 0, 'output_tokens' => 4]],
        ], 'anthropic');

        self::assertSame([['type' => 'text', 'text' => 'first '], ['type' => 'text', 'text' => 'second']], $merged['content']);
        self::assertSame(['input_tokens' => 9, 'output_tokens' => 4], $merged['usage'], 'a zero in message_delta does not reset a count');
    }

    public function testBedrockMergeOrdersBlocksByIndex(): void
    {
        $merged = (new StreamRebuilder())->merge([
            ['_event' => 'contentBlockDelta', 'contentBlockIndex' => 1, 'delta' => ['text' => 'B']],
            ['_event' => 'contentBlockDelta', 'contentBlockIndex' => 0, 'delta' => ['text' => 'A']],
        ], 'bedrock');

        self::assertSame([['text' => 'A'], ['text' => 'B']], \Axitdev\PhpUnitAiEvals\Support\Payload::get($merged, 'output', 'message', 'content'));
    }

    public function testOllamaMergeCollectsToolCallsFromEveryChunk(): void
    {
        $merged = (new StreamRebuilder())->merge([
            ['message' => ['content' => '', 'tool_calls' => [['function' => ['name' => 'a', 'arguments' => []]]]], 'done' => false],
            ['message' => ['content' => '', 'tool_calls' => [['function' => ['name' => 'b', 'arguments' => []]]]], 'done' => true],
        ], 'ollama');

        self::assertSame(['a', 'b'], array_column(array_column(\Axitdev\PhpUnitAiEvals\Support\Payload::maps(\Axitdev\PhpUnitAiEvals\Support\Payload::get($merged, 'message', 'tool_calls')), 'function'), 'name'));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function frame(array $headers, string $payload): string
    {
        $block = '';
        foreach ($headers as $name => $value) {
            $block .= chr(strlen($name) % 256) . $name . "\x07" . pack('n', strlen($value)) . $value;
        }
        $prelude = pack('NN', 16 + strlen($block) + strlen($payload), strlen($block));
        $message = $prelude . pack('N', crc32($prelude)) . $block . $payload;

        return $message . pack('N', crc32($message));
    }
}
