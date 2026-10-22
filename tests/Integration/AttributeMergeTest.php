<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Integration;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * A method attribute only overrides the fields it sets; the rest come from the class attribute.
 */
#[AiVcr(cassette: 'class-cassette', mode: VcrMode::Replay, ignorePatterns: ['/#\d+/'])]
#[AiJudgeConfig(model: 'class-judge', baseUri: 'http://judge.test/v1', temperature: 0.2, jsonMode: false)]
final class AttributeMergeTest extends TestCase
{
    use AiAssertionsTrait;
    use TempDirTrait;

    private const VARS = ['AI_EVAL_MODE', 'CI', 'GITHUB_ACTIONS', 'AI_EVAL_JUDGE_MODEL', 'AI_EVAL_JUDGE_BASE_URI', 'AI_EVAL_JUDGE_JSON_MODE', 'AI_EVAL_JUDGE_TEMPERATURE'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    #[Before(10)]
    protected function isolateEnv(): void
    {
        foreach (self::VARS as $var) {
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

    /**
     * @return array{string, array<string, mixed>, string} cassette text, judge request body, judge URI
     */
    private function recordAgentAndJudge(): array
    {
        $backend = new FakeClient(
            FakeClient::json(FakeClient::openAiReply('Order refunded.')),
            FakeClient::json(FakeClient::openAiReply('{"score": 1, "reason": "ok"}')),
        );
        $this->wrapHttpClient($backend)->sendRequest(FakeClient::request(
            'https://api.openai.com/v1/chat/completions',
            ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'Refund order #42']]],
        ));
        $this->assertMatchesRubric('Confirms the refund', 'Order refunded.');

        $files = glob($this->tmp . '/*.json') ?: [];
        self::assertCount(1, $files);

        return [(string) file_get_contents($files[0]), Payload::map(json_decode((string) $backend->requests[1]->getBody(), true)), (string) $backend->requests[1]->getUri()];
    }

    #[AiVcr(cassette: 'named')]
    public function testMethodCassetteKeepsTheClassMode(): void
    {
        $client = $this->wrapHttpClient(new FakeClient(FakeClient::json(FakeClient::openAiReply('hi'))));

        $this->expectException(CassetteMissException::class);
        $this->expectExceptionMessage('named.json');
        $client->sendRequest(FakeClient::request('https://api.openai.com/v1/chat/completions', ['model' => 'm', 'messages' => []]));
    }

    #[AiVcr(mode: VcrMode::Record)]
    #[AiJudgeConfig(jsonMode: true)]
    public function testMethodSettingsWinAndTheRestComeFromTheClass(): void
    {
        [$cassette, $judge, $uri] = $this->recordAgentAndJudge();

        self::assertFileExists($this->tmp . '/class-cassette.json');
        self::assertStringContainsString('Refund order <IGNORED>', $cassette);
        self::assertSame('http://judge.test/v1/chat/completions', $uri);
        self::assertSame(['class-judge', 0.2, ['type' => 'json_object']], [$judge['model'] ?? null, $judge['temperature'] ?? null, $judge['response_format'] ?? null]);
    }

    #[AiVcr(mode: VcrMode::Record, ignorePatterns: ['/Refund/'])]
    #[AiJudgeConfig(model: 'method-judge', baseUri: 'http://method.test/v1', temperature: 0.5)]
    public function testMethodValuesReplaceClassValuesForTheSameSetting(): void
    {
        [$cassette, $judge, $uri] = $this->recordAgentAndJudge();

        self::assertStringContainsString('<IGNORED> order #42', $cassette, 'method patterns replace the class patterns');
        self::assertSame('http://method.test/v1/chat/completions', $uri);
        self::assertSame(['method-judge', 0.5, null], [$judge['model'] ?? null, $judge['temperature'] ?? null, $judge['response_format'] ?? null]);
    }

    #[AiVcr(mode: VcrMode::Record, ignoreDatesAndUuids: false)]
    public function testDatesCanBeKeptInTheHashPerTest(): void
    {
        $this->wrapHttpClient(new FakeClient(FakeClient::json(FakeClient::openAiReply('Yes.'))))->sendRequest(FakeClient::request(
            'https://api.openai.com/v1/chat/completions',
            ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'Is 2025-12-25 a holiday?']]],
        ));

        self::assertStringContainsString('Is 2025-12-25 a holiday?', (string) file_get_contents($this->tmp . '/class-cassette.json'));
    }
}
