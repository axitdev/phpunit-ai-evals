<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Integration;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * Neither the class nor the method has #[AiVcr] or #[AiJudgeConfig]: everything comes from the environment.
 */
final class NoAttributesTest extends TestCase
{
    use AiAssertionsTrait;
    use TempDirTrait;

    private const ENV = ['AI_EVAL_MODE' => 'record', 'AI_EVAL_JUDGE_BASE_URI' => 'http://env.test/v1', 'AI_EVAL_JUDGE_MODEL' => 'env-judge'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    #[Before(10)]
    protected function setEnv(): void
    {
        foreach (self::ENV as $var => $value) {
            $this->savedEnv[$var] = getenv($var);
            putenv("$var=$value");
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

    public function testSettingsComeFromTheEnvironment(): void
    {
        $backend = new FakeClient(FakeClient::json(FakeClient::openAiReply('{"score": 1, "reason": "ok"}')));
        $this->wrapHttpClient($backend);
        $this->assertMatchesRubric('Says hello', 'Hello!');

        $judge = $backend->requests[0];
        self::assertSame('http://env.test/v1/chat/completions', (string) $judge->getUri());
        self::assertSame('env-judge', Payload::get(json_decode((string) $judge->getBody(), true), 'model'));
        self::assertFileExists($this->tmp . '/Axitdev_PhpUnitAiEvals_Tests_Integration_NoAttributesTest_testSettingsComeFromTheEnvironment.json');
    }
}
