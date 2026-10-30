<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Judge\CachedJudgeClient;
use Axitdev\PhpUnitAiEvals\Judge\JudgeRequest;
use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\SpyJudge;
use Axitdev\PhpUnitAiEvals\Tests\Support\VcrTestTrait;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use PHPUnit\Framework\TestCase;

/**
 * Judge verdicts recorded in and served from the cassette.
 */
final class VcrJudgeCacheTest extends TestCase
{
    use VcrTestTrait;

    public function testJudgeVerdictIsRecordedThenServedWithoutCallingLive(): void
    {
        $calls = 0;
        $live = function () use (&$calls): JudgeVerdict {
            $calls++;

            return new JudgeVerdict(0.95, 'grounded');
        };
        $payload = ['model' => 'gpt-6-luna', 'messages' => [['role' => 'user', 'content' => 'judge me 2026-01-01']]];

        $v1 = $this->client(new FakeClient(), VcrMode::Auto)->judgeVerdict($payload, $live);
        $v2 = $this->client(new FakeClient(), VcrMode::Replay)->judgeVerdict($payload, $live);

        self::assertSame(1, $calls);
        self::assertEquals($v1, $v2);
        self::assertSame('judge_eval', $this->cassette()->interactions()[0]['kind']);
        self::assertSame(0.95, Payload::get($this->cassette()->interactions()[0], 'verdict', 'score'));
    }

    public function testAnyJudgeIsCachedOnEveryKeyOfItsCachePayload(): void
    {
        $spy = new SpyJudge(1.0);
        (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Record), $spy))->judge(JudgeRequest::rubric('rubric A', 'output'));

        $entry = $this->cassette()->interactions()[0];
        self::assertSame('spy', $entry['model']);
        self::assertSame(['rubric A'], Payload::get($entry, 'request', 'normalized_payload', 'request', 'criteria'), 'keys outside the provider allow-list are kept');
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, Payload::string($entry['recorded_at'] ?? null)));

        $replayed = new SpyJudge(0.0);
        self::assertSame(1.0, (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Replay), $replayed))->judge(JudgeRequest::rubric('rubric A', 'output'))->score);
        self::assertSame(0, $replayed->calls);

        $this->expectException(CassetteMissException::class);
        (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Replay), $spy))->judge(JudgeRequest::rubric('rubric B', 'output'));
    }

    public function testLlmJudgeVerdictsKeepTheHashTheyWereRecordedUnder(): void
    {
        // Cassettes recorded before judges had their own cache payload hashed it with the provider allow-list.
        $normalizer = new \Axitdev\PhpUnitAiEvals\Vcr\PromptNormalizer();
        $payload = (new \Axitdev\PhpUnitAiEvals\Judge\Psr18JudgeClient(new FakeClient(), new \Axitdev\PhpUnitAiEvals\Judge\JudgeSettings('http://j.test/v1', 'gpt-6-luna', 'k')))
            ->cachePayload(JudgeRequest::rubric('be polite on 2026-01-01', 'hi'));

        self::assertSame($normalizer->hash($normalizer->normalize($payload)), $normalizer->hash($normalizer->normalizeJudge($payload)));
        self::assertStringContainsString('<TIMESTAMP>', (string) json_encode($normalizer->normalizeJudge($payload)), 'volatile values are still replaced');
    }

    public function testJudgeVerdictMissInReplayThrowsAndLiveModeSkipsCache(): void
    {
        $payload = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'q']]];
        $live = static fn(): JudgeVerdict => new JudgeVerdict(0.1, 'bad');

        try {
            $this->client(new FakeClient(), VcrMode::Replay)->judgeVerdict($payload, $live);
            self::fail('expected miss');
        } catch (CassetteMissException) {
        }

        $verdict = $this->client(new FakeClient(), VcrMode::Live)->judgeVerdict($payload, $live);
        self::assertSame(0.1, $verdict->score);
        self::assertNull($this->storage->load($this->storage->pathFor('T::t')));
    }
}
