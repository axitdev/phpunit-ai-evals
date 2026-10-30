<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\SecretScrubber;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AiVcr(cassette: 'safety', mode: VcrMode::Replay)]
final class SafetyTest extends TestCase
{
    use AiAssertionsTrait;
    use TempDirTrait;

    private const URI = 'https://api.openai.com/v1/chat/completions';

    protected function aiCassettesPath(): string
    {
        return $this->tmp;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentials(): iterable
    {
        yield 'OpenAI project key' => ['sk-proj-' . str_repeat('aB3', 12)];
        yield 'Anthropic key' => ['sk-ant-api03-' . str_repeat('Xy9_', 8)];
        yield 'Google API key' => ['AIza' . str_repeat('A1b2C3d4e5', 3) . 'F6g7h'];
        yield 'AWS access key id' => ['AKIA' . 'ABCDEFGHIJKLMNOP'];
        yield 'GitHub token' => ['ghp_' . str_repeat('a1B2', 9)];
        yield 'Slack token' => ['xoxb-1234567890-abcdefghij'];
        yield 'Hugging Face token' => ['hf_' . str_repeat('abcDEF123', 4)];
        yield 'JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'];
    }

    #[DataProvider('credentials')]
    public function testCredentialShapesAreRedactedInText(string $secret): void
    {
        self::assertSame('use [REDACTED] here', (new SecretScrubber())->scrubText("use $secret here"));
    }

    public function testBearerKeepsItsScheme(): void
    {
        self::assertSame('Authorization: Bearer [REDACTED]', (new SecretScrubber())->scrubText('Authorization: Bearer abc.def-123_456'));
    }

    public function testOrdinaryTextIsLeftAlone(): void
    {
        $text = 'Ask the sk-team about task-12345 and the risk of tokens: 1200 prompt, 300 completion.';

        self::assertSame($text, (new SecretScrubber())->scrubText($text));
    }

    public function testExtraPatternsAndInvalidOnes(): void
    {
        self::assertSame('id [REDACTED]', (new SecretScrubber(extraPatterns: ['/acme_[0-9a-f]{8}/']))->scrubText('id acme_deadbeef'));

        $this->expectException(InvalidArgumentException::class);
        new SecretScrubber(extraPatterns: ['/unclosed']);
    }

    public function testSecretsInPromptsResponsesHeadersAndTracesNeverReachTheCassette(): void
    {
        $key = 'sk-proj-' . str_repeat('Zz9', 12);
        $payload = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => "My key is $key, store it"]]];
        $reply = FakeClient::openAiReply("Stored $key", [['store_secret', ['value' => $key]]]);
        $client = $this->vcr(VcrMode::Record, new FakeClient(FakeClient::json($reply)));

        $client->sendRequest(FakeClient::request(self::URI, $payload)->withHeader('X-Debug', "Bearer $key"));

        $cassette = (string) file_get_contents((new CassetteStorage($this->tmp))->pathFor('safety'));
        self::assertStringNotContainsString($key, $cassette);
        self::assertStringContainsString('"X-Debug": [' . "\n" . '                        "Bearer [REDACTED]"', $cassette);

        // The same request still replays: the hash is taken after redaction.
        $this->vcr(VcrMode::Replay, new FakeClient())->sendRequest(FakeClient::request(self::URI, $payload));
    }

    public function testReplayNeverCallsTheInnerClient(): void
    {
        $inner = new FakeClient(FakeClient::json(FakeClient::openAiReply('should not be used')));
        $client = $this->vcr(VcrMode::Replay, $inner);

        try {
            $client->sendRequest(FakeClient::request(self::URI, ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]]));
            self::fail('expected a miss');
        } catch (CassetteMissException) {
        }

        self::assertSame([], $inner->requests);
        self::assertCount(1, $client->misses());
    }

    public function testASwallowedReplayMissStillFailsTheTest(): void
    {
        $vcr = $this->wrapHttpClient(new FakeClient());
        try {
            $vcr->sendRequest(FakeClient::request(self::URI, ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]]));
        } catch (\Throwable) {
            // what an app's fallback or retry wrapper might do
        }

        try {
            $this->failOnSwallowedCassetteMisses();
            self::fail('expected the guard to fail the test');
        } catch (AssertionFailedError $e) {
            self::assertStringStartsWith('1 request(s) missed the cassette in replay mode, and the code under test caught the error', $e->getMessage());
            self::assertStringContainsString('No recorded agent_call', $e->getMessage());
        }
    }

    private function vcr(VcrMode $mode, FakeClient $inner): AiVcrClient
    {
        $storage = new CassetteStorage($this->tmp);

        return new AiVcrClient($inner, $storage, $storage->pathFor('safety'), 'T::t', $mode, new AiTraceCollector());
    }
}
