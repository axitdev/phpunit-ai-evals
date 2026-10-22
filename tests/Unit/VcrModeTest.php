<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VcrModeTest extends TestCase
{
    private const VARS = ['AI_EVAL_MODE', 'CI', 'GITHUB_ACTIONS'];

    /** @var array<string, string|false> */
    private array $saved = [];

    #[Before]
    protected function clearEnv(): void
    {
        foreach (self::VARS as $var) {
            $this->saved[$var] = getenv($var);
            putenv($var);
        }
    }

    #[After]
    protected function restoreEnv(): void
    {
        foreach ($this->saved as $var => $value) {
            putenv($value === false ? $var : "$var=$value");
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, VcrMode, VcrMode}>
     */
    public static function cases(): iterable
    {
        yield 'no env keeps auto' => [[], VcrMode::Auto, VcrMode::Auto];
        yield 'CI downgrades auto to replay' => [['CI' => 'true'], VcrMode::Auto, VcrMode::Replay];
        yield 'github actions downgrades' => [['GITHUB_ACTIONS' => 'true'], VcrMode::Auto, VcrMode::Replay];
        yield 'CI keeps explicit record' => [['CI' => '1'], VcrMode::Record, VcrMode::Record];
        yield 'env live wins over CI' => [['CI' => '1', 'AI_EVAL_MODE' => 'live'], VcrMode::Auto, VcrMode::Live];
        yield 'env record wins over CI' => [['CI' => '1', 'AI_EVAL_MODE' => 'record'], VcrMode::Replay, VcrMode::Record];
        yield 'env overrides attribute' => [['AI_EVAL_MODE' => 'REPLAY'], VcrMode::Live, VcrMode::Replay];
        yield 'env auto still downgraded on CI' => [['CI' => '1', 'AI_EVAL_MODE' => 'auto'], VcrMode::Record, VcrMode::Replay];
        yield 'empty env ignored' => [['AI_EVAL_MODE' => ''], VcrMode::Record, VcrMode::Record];
        yield 'CI=false is not CI' => [['CI' => 'FALSE'], VcrMode::Auto, VcrMode::Auto];
        yield 'CI=0 is not CI' => [['CI' => '0', 'GITHUB_ACTIONS' => ''], VcrMode::Auto, VcrMode::Auto];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('cases')]
    public function testResolve(array $env, VcrMode $configured, VcrMode $expected): void
    {
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }

        self::assertSame($expected, VcrMode::resolve($configured));
    }

    public function testInvalidEnvModeThrows(): void
    {
        putenv('AI_EVAL_MODE=recrod');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid AI_EVAL_MODE "recrod"; expected one of: record, replay, auto, live.');
        VcrMode::resolve(VcrMode::Auto);
    }

    public function testReplayAndRecordFlags(): void
    {
        self::assertSame([true, false], [VcrMode::Replay->replays(), VcrMode::Replay->records()]);
        self::assertSame([false, true], [VcrMode::Record->replays(), VcrMode::Record->records()]);
        self::assertSame([true, true], [VcrMode::Auto->replays(), VcrMode::Auto->records()]);
        self::assertSame([false, false], [VcrMode::Live->replays(), VcrMode::Live->records()]);
    }
}
