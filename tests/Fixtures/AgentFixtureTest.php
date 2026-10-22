<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Fixtures;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executed by ExtensionEndToEndTest in a child PHPUnit process; not part of the regular suites.
 */
final class AgentFixtureTest extends TestCase
{
    use AiAssertionsTrait;

    /**
     * @return iterable<string, array{string}>
     */
    public static function prompts(): iterable
    {
        yield 'first' => ['refund 404'];
        yield 'second' => ['refund 405'];
    }

    #[DataProvider('prompts')]
    public function testAgent(string $prompt): void
    {
        $client = $this->wrapHttpClient(new FakeClient(
            FakeClient::json(FakeClient::openAiReply('done', [['issue_refund', ['order_id' => 404]]], 100, 20)),
            FakeClient::json(FakeClient::openAiReply('{"score": 0.9, "reason": "ok"}')),
        ));

        $client->sendRequest(new Request('POST', 'https://api.openai.com/v1/chat/completions', ['X-Tenant-Token' => 'TENANT-SECRET'], json_encode([
            'model' => 'gpt-6-luna',
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], JSON_THROW_ON_ERROR)));

        self::assertToolCalled('issue_refund', ['order_id' => 404]);
        self::assertFalse((bool) getenv('AI_EVAL_FIXTURE_FAIL'), 'failing on purpose for ExtensionEndToEndTest');
        $this->assertMatchesRubric('says done', 'done');
    }
}
