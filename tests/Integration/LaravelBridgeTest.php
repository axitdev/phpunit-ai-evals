<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Integration;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Laravel\FakesAiHttp;
use Axitdev\PhpUnitAiEvals\Laravel\PassthroughClient;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[AiVcr(cassette: 'laravel')]
final class LaravelBridgeTest extends TestCase
{
    use AiAssertionsTrait;
    use FakesAiHttp;
    use TempDirTrait;

    private const LLM = 'https://api.openai.com/v1/chat/completions';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    #[Before(10)]
    protected function isolateEnv(): void
    {
        foreach (['AI_EVAL_MODE', 'CI', 'GITHUB_ACTIONS'] as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    #[After]
    protected function restoreEnv(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv($value === false ? $var : "$var=$value");
        }
    }

    protected function aiCassettesPath(): string
    {
        return $this->tmp;
    }

    public function testRecordsThroughTheProviderThenReplaysWithoutIt(): void
    {
        putenv('AI_EVAL_MODE=record');
        $live = new FakeClient(FakeClient::json(FakeClient::openAiReply('Bonjour', [['save_note', ['text' => 'hi']]])));
        $http = new Factory();
        $this->fakeAiHttp('api.openai.com/*', $http, $live);

        $response = $http->withToken('sk-live-0123456789abcdefghijklmn')->post(self::LLM, ['model' => 'gpt-6-luna', 'messages' => [['role' => 'user', 'content' => 'Say hi in French']]]);

        self::assertSame('Bonjour', $response->json('choices.0.message.content'));
        self::assertCount(1, $live->requests);
        self::assertToolCalled('save_note', ['text' => 'hi']);
        $cassette = (string) file_get_contents((new CassetteStorage($this->tmp))->pathFor('laravel'));
        self::assertStringNotContainsString('sk-live-0123456789abcdefghijklmn', $cassette);

        putenv('AI_EVAL_MODE=replay');
        $this->resetAiEvalState();
        $http = new Factory();
        $this->fakeAiHttp('api.openai.com/*', $http, new FakeClient());

        $replayed = $http->post(self::LLM, ['model' => 'gpt-6-luna', 'messages' => [['role' => 'user', 'content' => 'Say hi in French']]]);
        self::assertSame('Bonjour', $replayed->json('choices.0.message.content'));
        self::assertToolCalled('save_note');
        $http->assertSentCount(1); // Laravel's own fake assertions keep working
    }

    public function testAttachedFilesAreRecordedAsHashes(): void
    {
        putenv('AI_EVAL_MODE=auto');
        $bytes = "\x89PNG\r\n\x1a\n" . random_bytes(32);
        $upload = static fn(Factory $http) => $http->attach('file', $bytes, 'scan.png', ['Content-Type' => 'image/png'])
            ->post('https://api.example.com/v1/files', ['purpose' => 'vision']);

        $live = new FakeClient(FakeClient::json(['id' => 'file-1']));
        $http = new Factory();
        $this->fakeAiHttp('*', $http, $live);
        self::assertSame('file-1', $upload($http)->json('id'));

        $this->resetAiEvalState();
        $http = new Factory();
        $this->fakeAiHttp('*', $http, new FakeClient());
        self::assertSame('file-1', $upload($http)->json('id'), 'a new multipart boundary still matches');

        $stored = (new CassetteStorage($this->tmp))->load((new CassetteStorage($this->tmp))->pathFor('laravel'))?->interactions()[0];
        self::assertSame(
            ['filename' => 'scan.png', 'mime' => 'image/png', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)],
            Payload::get($stored, 'request', 'normalized_payload', 'body', 'multipart', 'file'),
        );
        self::assertSame('vision', Payload::get($stored, 'request', 'normalized_payload', 'body', 'multipart', 'purpose'));
    }

    public function testReplayModeBlocksCallsOutsideThePatterns(): void
    {
        putenv('AI_EVAL_MODE=replay');
        $http = new Factory();
        $this->fakeAiHttp('api.openai.com/*', $http, new FakeClient());

        // StrayRequestException (a RuntimeException) arrived in Laravel 11.x; 11.0 throws a plain RuntimeException.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('https://example.com/');
        $http->get('https://example.com/');
    }

    public function testPassthroughKeepsTransportOptionsAndNeverThrowsOnStatus(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            static function (RequestInterface $request, array $options) use (&$sent): Response {
                $sent = $options;

                return new Response(500, [], 'boom');
            },
        ]));
        $client = new PassthroughClient(new Client(['handler' => $stack]));
        $client->useOptions(['timeout' => 7, 'laravel_data' => ['x' => 1], 'json' => ['ignored' => true]]);

        $response = $client->sendRequest(FakeClient::request(self::LLM, []));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(7, $sent['timeout'] ?? null);
        self::assertArrayNotHasKey('laravel_data', $sent);
        self::assertArrayNotHasKey('json', $sent);
    }

    public function testModeComesFromTheUsualSettings(): void
    {
        putenv('AI_EVAL_MODE=live');

        self::assertSame(VcrMode::Live, $this->fakeAiHttp('*', new Factory(), new FakeClient())->mode());
    }
}
