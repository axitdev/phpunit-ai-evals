<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AttributesAndDtoTest extends TestCase
{
    public function testAiJudgeConfigDefaults(): void
    {
        $config = new AiJudgeConfig(model: 'gpt-6-luna');

        self::assertSame('gpt-6-luna', $config->model);
        self::assertNull($config->baseUri);
        self::assertNull($config->temperature);
    }

    public function testAiInteractionComputesTotalAndNormalizesToolCalls(): void
    {
        $interaction = new AiInteraction('openai', 'gpt-6-luna', 140, 35, 5, 12.5, 'hi', [3 => new ToolCall('a')]);

        self::assertSame(175, $interaction->totalTokens);
        self::assertSame(['a'], array_map(static fn(ToolCall $c): string => $c->name, $interaction->toolCalls));
        self::assertArrayHasKey(0, $interaction->toolCalls);
    }

    public function testJudgeVerdictValidatesScoreRange(): void
    {
        self::assertSame(1.0, (new JudgeVerdict(1.0, 'ok'))->score);
        self::assertSame(0.0, (new JudgeVerdict(0.0, 'bad'))->score);

        $this->expectException(InvalidArgumentException::class);
        new JudgeVerdict(1.5, 'nope');
    }
}

#[AiVcr(cassette: 'x.json', mode: VcrMode::Replay, ignorePatterns: ['/foo/'])]
final class AttributedFixture {}
