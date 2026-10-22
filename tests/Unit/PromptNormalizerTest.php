<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Vcr\PromptNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PromptNormalizerTest extends TestCase
{
    private static function hashOf(PromptNormalizer $n, string $json): string
    {
        return $n->hash($n->normalize(Payload::map(json_decode($json, true, 512, JSON_THROW_ON_ERROR))));
    }

    public function testKeepsOnlySemanticFieldsAndDropsVolatileOnes(): void
    {
        $normalized = (new PromptNormalizer())->normalize(([
            'model' => 'gpt-6-luna',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'temperature' => 0.7,
            'top_p' => 1,
            'seed' => 42,
            'user' => 'u1',
            'metadata' => ['a' => 1],
            'stream_options' => ['include_usage' => true],
            'tool_choice' => 'auto',
            'response_format' => ['type' => 'json_object'],
        ]));

        self::assertSame(['messages', 'model', 'response_format', 'tool_choice'], array_keys($normalized));
    }

    public function testHashIgnoresVolatileFieldsAndKeyOrder(): void
    {
        $n = new PromptNormalizer();
        $a = self::hashOf($n, '{"model":"m","messages":[{"role":"user","content":"x"}],"temperature":0.1}');
        $b = self::hashOf($n, '{"seed":9,"messages":[{"content":"x","role":"user"}],"model":"m"}');

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, self::hashOf($n, '{"model":"m","messages":[{"role":"user","content":"y"}]}'));
    }

    public function testListOrderStaysSignificant(): void
    {
        $n = new PromptNormalizer();

        self::assertNotSame(
            self::hashOf($n, '{"messages":[{"content":"a"},{"content":"b"}]}'),
            self::hashOf($n, '{"messages":[{"content":"b"},{"content":"a"}]}'),
        );
    }

    public function testSortsNestedToolArgumentJsonStrings(): void
    {
        $n = new PromptNormalizer();
        $msg = static fn(string $args): array => (['messages' => [[
            'role' => 'assistant',
            'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'refund', 'arguments' => $args]]],
        ]]]);

        $a = $n->normalize($msg('{"order":{"z":1,"a":[{"y":2,"x":1}]},"id":404}'));
        $b = $n->normalize($msg('{"id":404,"order":{"a":[{"x":1,"y":2}],"z":1}}'));

        self::assertSame($a, $b);
        self::assertSame(
            '{"id":404,"order":{"a":[{"x":1,"y":2}],"z":1}}',
            Payload::get($a, 'messages', 0, 'tool_calls', 0, 'function', 'arguments'),
        );
    }

    public function testNonJsonArgumentStringsAreLeftAlone(): void
    {
        $out = (new PromptNormalizer())->normalize((['messages' => [['arguments' => 'not json']]]));

        self::assertSame('not json', Payload::get($out, 'messages', 0, 'arguments'));
    }

    public function testScrubsTimestampsUuidsAndBase64(): void
    {
        $uri = 'data:image/png;base64,AAAA';
        $out = (new PromptNormalizer())->normalize((['messages' => [['role' => 'user', 'content'
            => "On 2026-10-22T01:00:00+00:00 and 2026-10-22 01:00:00.123Z and 2026-10-22, id 123e4567-e89b-42d3-a456-426614174000 / "
            . "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b $uri",
        ]]]));

        self::assertSame(
            'On <TIMESTAMP> and <TIMESTAMP> and <TIMESTAMP>, id <UUID> / <UUID> <BASE64_SHA256:' . hash('sha256', $uri) . '>',
            Payload::get($out, 'messages', 0, 'content'),
        );
    }

    public function testHashesAnthropicBase64ImageSources(): void
    {
        $out = (new PromptNormalizer())->normalize((['messages' => [['content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'AAAA']],
        ]]]]));

        self::assertSame(
            '<BASE64_SHA256:' . hash('sha256', 'AAAA') . '>',
            Payload::get($out, 'messages', 0, 'content', 0, 'source', 'data'),
        );
    }

    public function testRemovesAnthropicThinkingSignatures(): void
    {
        $n = new PromptNormalizer();
        $body = static fn(string $sig): array => (['messages' => [['role' => 'assistant', 'content' => [
            ['type' => 'thinking', 'thinking' => 'hmm', 'signature' => $sig],
            ['type' => 'text', 'text' => 'ok', 'signature' => 'keep'],
        ]]]]);

        $out = $n->normalize($body('sigA'));

        self::assertSame($n->hash($out), $n->hash($n->normalize($body('sigB'))));
        self::assertArrayNotHasKey('signature', Payload::map(Payload::get($out, 'messages', 0, 'content', 0)));
        self::assertSame('keep', Payload::get($out, 'messages', 0, 'content', 1, 'signature'));
    }

    public function testCustomIgnorePatterns(): void
    {
        $n = new PromptNormalizer(['/session-\w+/', '/req#\d+/']);

        self::assertSame(
            self::hashOf($n, '{"messages":[{"content":"session-abc req#1"}]}'),
            self::hashOf($n, '{"messages":[{"content":"session-xyz req#99"}]}'),
        );
        $out = $n->normalize(['messages' => [['content' => 'session-abc req#1']]]);
        self::assertSame('<IGNORED> <IGNORED>', Payload::get($out, 'messages', 0, 'content'));
    }

    public function testInvalidCustomPatternThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PromptNormalizer(['/(?:a+)+$/']))->normalize(['messages' => [['content' => str_repeat('a', 5000) . '!']]]);
    }

    public function testStreamFlagIsSemanticButFalseEqualsAbsent(): void
    {
        $n = new PromptNormalizer();
        $base = '{"model":"m","messages":[{"content":"x"}]';

        self::assertSame(self::hashOf($n, $base . '}'), self::hashOf($n, $base . ',"stream":false}'));
        self::assertNotSame(self::hashOf($n, $base . '}'), self::hashOf($n, $base . ',"stream":true}'));
    }

    public function testKeepsResponsesApiFields(): void
    {
        $out = (new PromptNormalizer())->normalize(['model' => 'm', 'input' => 'hi', 'instructions' => 'be brief', 'previous_response_id' => 'resp_1', 'store' => true]);

        self::assertSame(['input', 'instructions', 'model', 'previous_response_id'], array_keys($out));
    }

    public function testPreservesUnicodeAndSlashesInHash(): void
    {
        $n = new PromptNormalizer();

        self::assertSame(
            hash('sha256', '{"messages":[{"content":"héllo/世界"}]}'),
            self::hashOf($n, '{"messages":[{"content":"héllo\/世界"}]}'),
        );
    }

    public function testOnlyBase64SourcesAreHashedAndToolArgumentsKeepUnicode(): void
    {
        $out = (new PromptNormalizer())->normalize(['messages' => [
            ['content' => [['type' => 'text', 'data' => 'not an image']]],
            ['tool_calls' => [['function' => ['arguments' => '{"b":"café/x","a":1}']]]],
        ]]);

        self::assertSame('not an image', Payload::get($out, 'messages', 0, 'content', 0, 'data'));
        self::assertSame('{"a":1,"b":"café/x"}', Payload::get($out, 'messages', 1, 'tool_calls', 0, 'function', 'arguments'));
    }

    public function testReasoningAndStructuredOutputSettingsAreSemantic(): void
    {
        $n = new PromptNormalizer();
        $base = '"model":"m","messages":[{"role":"user","content":"x"}]';
        $plain = self::hashOf($n, '{' . $base . '}');

        foreach ([
            '"thinking":{"type":"enabled","budget_tokens":2000}',
            '"reasoning":{"effort":"high"}',
            '"reasoning_effort":"high"',
            '"output_config":{"format":{"type":"json_schema"}}',
            '"output_format":{"type":"json_schema"}',
            '"think":true',
        ] as $field) {
            self::assertNotSame($plain, self::hashOf($n, '{' . $base . ',' . $field . '}'), $field);
        }
    }

    public function testOnlyGeminiOutputSettingsOfGenerationConfigAreSemantic(): void
    {
        $n = new PromptNormalizer();
        $contents = '"contents":[{"parts":[{"text":"hi"}]}]';
        $sampling = self::hashOf($n, '{' . $contents . ',"generationConfig":{"temperature":0.2,"maxOutputTokens":10}}');

        self::assertSame(self::hashOf($n, '{' . $contents . '}'), $sampling, 'sampling settings are ignored');
        self::assertSame(
            ['contents', 'generationConfig'],
            array_keys($n->normalize(['contents' => [], 'generationConfig' => ['temperature' => 1, 'responseMimeType' => 'application/json']])),
        );
        self::assertSame(
            ['responseMimeType' => 'application/json', 'responseSchema' => ['type' => 'object']],
            $n->normalize(['generationConfig' => ['temperature' => 1, 'responseSchema' => ['type' => 'object'], 'responseMimeType' => 'application/json']])['generationConfig'],
        );
        self::assertNotSame(
            self::hashOf($n, '{' . $contents . ',"generationConfig":{"responseSchema":{"type":"object"}}}'),
            self::hashOf($n, '{' . $contents . ',"generationConfig":{"responseSchema":{"type":"array"}}}'),
        );
    }

    public function testDatesInsideLongerTokensAreLeftAlone(): void
    {
        $scrub = static fn(string $text): mixed => (new PromptNormalizer())->normalize(['prompt' => $text])['prompt'];

        self::assertSame('Call 555-1234-56-78 now', $scrub('Call 555-1234-56-78 now'));
        self::assertSame('ref A2025-12-25 and 2025-12-25-backup', $scrub('ref A2025-12-25 and 2025-12-25-backup'));
        self::assertSame('gpt-4o-2024-08-06', (new PromptNormalizer())->normalize(['model' => 'gpt-4o-2024-08-06'])['model'], 'dated model versions stay distinct');
        self::assertSame('on <TIMESTAMP>, at <TIMESTAMP>.', $scrub('on 2025-12-25, at 2025-12-25T10:00:00Z.'));
    }

    public function testDateAndUuidScrubbingCanBeTurnedOff(): void
    {
        $prompt = ['prompt' => 'Is 2025-12-25 a holiday? 123e4567-e89b-42d3-a456-426614174000'];

        self::assertSame('Is <TIMESTAMP> a holiday? <UUID>', (new PromptNormalizer())->normalize($prompt)['prompt']);
        self::assertSame($prompt, (new PromptNormalizer([], false))->normalize($prompt));
        self::assertSame(['prompt' => 'Is <IGNORED> a holiday? 123e4567-e89b-42d3-a456-426614174000'], (new PromptNormalizer(['/\d{4}-\d\d-\d\d/'], false))->normalize($prompt));
    }

    public function testRawRequestsLoseVolatileValuesButKeepEverythingElse(): void
    {
        $n = new PromptNormalizer(['/nonce=\w+/']);

        self::assertSame(
            ['method' => 'POST', 'uri' => 'https://a.test/orders/<UUID>', 'body' => '{"at":"<TIMESTAMP>","q":1,"<IGNORED>"}'],
            $n->normalizeRaw('POST', 'https://a.test/orders/123e4567-e89b-42d3-a456-426614174000', '{"at":"2025-12-25T10:00:00Z","q":1,"nonce=abc"}'),
        );
    }
}
