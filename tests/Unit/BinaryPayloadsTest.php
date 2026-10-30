<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\VcrTestTrait;
use Axitdev\PhpUnitAiEvals\Vcr\BinaryContent;
use Axitdev\PhpUnitAiEvals\Vcr\PromptNormalizer;
use Axitdev\PhpUnitAiEvals\Vcr\RequestBody;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use GuzzleHttp\Psr7\MultipartStream;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class BinaryPayloadsTest extends TestCase
{
    use VcrTestTrait;

    private const URI = 'https://api.openai.com/v1/chat/completions';

    private const UPLOAD = 'https://api.example.com/v1/files';

    /** A 1x1 PNG. */
    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    public function testDataUriBecomesHashSizeAndMime(): void
    {
        $png = self::png();
        $out = (new PromptNormalizer())->normalize(['model' => 'm', 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode($png)]],
        ]]]]);

        self::assertSame(
            sprintf('<binary sha256=%s size=%d mime=image/png>', hash('sha256', $png), strlen($png)),
            Payload::get($out, 'messages', 0, 'content', 0, 'image_url', 'url'),
        );
    }

    public function testSameImageHashesTheSameAcrossProviderShapes(): void
    {
        $png = self::png();
        $b64 = base64_encode($png);
        $normalizer = new PromptNormalizer();

        $openAi = $normalizer->normalize(['messages' => [['content' => 'data:image/png;base64,' . $b64]]]);
        $anthropic = $normalizer->normalize(['messages' => [['content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $b64]]]]]]);
        $gemini = $normalizer->normalize(['contents' => [['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => $b64]]]]]]);

        $expected = BinaryContent::placeholder($png, 'image/png');
        self::assertSame($expected, Payload::get($openAi, 'messages', 0, 'content'));
        self::assertSame($expected, Payload::get($anthropic, 'messages', 0, 'content', 0, 'source', 'data'));
        self::assertSame($expected, Payload::get($gemini, 'contents', 0, 'parts', 0, 'inlineData', 'data'));
    }

    public function testOpenAiInputAudioUsesTheFormatAsMime(): void
    {
        $audio = str_repeat("RIFF\x00\x01", 20);
        $out = (new PromptNormalizer())->normalize(['messages' => [['content' => [
            ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($audio), 'format' => 'wav']],
        ]]]]);

        self::assertSame(BinaryContent::placeholder($audio, 'audio/wav'), Payload::get($out, 'messages', 0, 'content', 0, 'input_audio', 'data'));
    }

    public function testShortBareStringsCalledDataAreLeftAlone(): void
    {
        $out = (new PromptNormalizer())->normalize(['messages' => [['content' => [['data' => 'abc123', 'mimeType' => 'text/plain']]]]]);

        self::assertSame('abc123', Payload::get($out, 'messages', 0, 'content', 0, 'data'));
    }

    public function testRequestWithDataUriRecordsAndReplaysWithoutTheBytes(): void
    {
        $png = self::png();
        $payload = ['model' => 'gpt-6-luna', 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode($png)]],
        ]]]];

        $this->client(new FakeClient(FakeClient::json(FakeClient::openAiReply('A pixel.'))), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, $payload));
        $json = (string) file_get_contents($this->storage->pathFor('T::t'));

        self::assertStringNotContainsString(base64_encode($png), $json);
        self::assertStringContainsString('size=' . strlen($png) . ' mime=image/png', $json);

        $reply = $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $payload));
        self::assertStringContainsString('A pixel.', (string) $reply->getBody());
    }

    public function testMultipartUploadStoresFieldsAndFileDescriptors(): void
    {
        $png = self::png();
        $request = static fn(string $boundary, string $purpose = 'vision'): Request => self::multipartRequest($boundary, [
            ['name' => 'purpose', 'contents' => $purpose],
            ['name' => 'file', 'contents' => $png, 'filename' => 'pixel.png', 'headers' => ['Content-Type' => 'image/png']],
        ]);

        $this->client(new FakeClient(FakeClient::json(['id' => 'file-1'])), VcrMode::Record)->sendRequest($request('boundary-one'));
        $stored = Payload::map(Payload::get($this->cassette()->interactions()[0], 'request', 'normalized_payload'));

        self::assertSame(
            ['multipart' => [
                'file' => ['filename' => 'pixel.png', 'mime' => 'image/png', 'sha256' => hash('sha256', $png), 'size' => strlen($png)],
                'purpose' => 'vision',
            ]],
            $stored['body'],
        );
        self::assertStringNotContainsString(base64_encode($png), (string) file_get_contents($this->storage->pathFor('T::t')));

        // A new random boundary still matches; a changed field doesn't.
        $replay = $this->client(new FakeClient(), VcrMode::Replay);
        self::assertSame('{"id":"file-1"}', (string) $replay->sendRequest($request('boundary-two'))->getBody());
        $this->expectExceptionMessage('body.multipart.purpose');
        $replay->sendRequest($request('boundary-three', 'fine-tune'));
    }

    public function testMultipartWithAChangedFileMisses(): void
    {
        $upload = static fn(string $bytes): Request => self::multipartRequest('b', [['name' => 'file', 'contents' => $bytes, 'filename' => 'a.bin']]);
        $this->client(new FakeClient(FakeClient::json(['ok' => true])), VcrMode::Record)->sendRequest($upload("\x00\x01\x02"));

        $this->expectExceptionMessage('body.multipart.file.sha256');
        $this->client(new FakeClient(), VcrMode::Replay)->sendRequest($upload("\x00\x01\x03"));
    }

    public function testRepeatedMultipartNamesKeepEveryValue(): void
    {
        $body = (string) self::multipartRequest('x', [
            ['name' => 'tags[]', 'contents' => 'a'],
            ['name' => 'tags[]', 'contents' => 'b'],
        ])->getBody();

        self::assertSame(['multipart' => ['tags[]' => ['a', 'b']]], RequestBody::describe($body, 'multipart/form-data; boundary=x'));
    }

    public function testSecretMultipartFieldsAreRedacted(): void
    {
        $this->client(new FakeClient(FakeClient::json([])), VcrMode::Record)
            ->sendRequest(self::multipartRequest('b', [['name' => 'api_key', 'contents' => 'k-123'], ['name' => 'q', 'contents' => 'x']]));

        self::assertSame('[REDACTED]', Payload::get($this->cassette()->interactions()[0], 'request', 'normalized_payload', 'body', 'multipart', 'api_key'));
    }

    public function testRawBinaryBodyBecomesOneDescriptor(): void
    {
        $bytes = "\x89PNG\x00\x00\xff\xfe";
        $request = new Request('PUT', self::UPLOAD, ['Content-Type' => 'application/octet-stream'], $bytes);
        $this->client(new FakeClient(new Response(201)), VcrMode::Record)->sendRequest($request);

        self::assertSame(
            ['binary' => ['mime' => 'application/octet-stream', 'sha256' => hash('sha256', $bytes), 'size' => 8]],
            Payload::get($this->cassette()->interactions()[0], 'request', 'normalized_payload', 'body'),
        );
        self::assertSame(201, $this->client(new FakeClient(), VcrMode::Replay)->sendRequest($request)->getStatusCode());
    }

    public function testRawJsonBodiesAreStoredDecoded(): void
    {
        $this->client(new FakeClient(FakeClient::json([])), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::UPLOAD, ['b' => 1, 'a' => ['x' => 'y']]));

        self::assertSame(['json' => ['a' => ['x' => 'y'], 'b' => 1]], Payload::get($this->cassette()->interactions()[0], 'request', 'normalized_payload', 'body'));
    }

    /**
     * @param list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}> $parts
     */
    private static function multipartRequest(string $boundary, array $parts): Request
    {
        return new Request('POST', self::UPLOAD, ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], new MultipartStream($parts, $boundary));
    }
}
