<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Judge\CachedJudgeClient;
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

    public function testCachedJudgeKeysOnTheSystemPromptAndRoles(): void
    {
        $spy = new SpyJudge(1.0);
        (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Record), $spy, 'm'))->judge('rubric A', 'output');

        $entry = $this->cassette()->interactions()[0];
        self::assertSame(['system', 'user'], array_column(Payload::maps(Payload::get($entry, 'request', 'normalized_payload', 'messages')), 'role'));
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, Payload::string($entry['recorded_at'] ?? null)));

        $this->expectException(CassetteMissException::class);
        (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Replay), $spy, 'm'))->judge('rubric B', 'output');
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
