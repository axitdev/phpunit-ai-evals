<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Integration;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Conversation\Conversation;
use Axitdev\PhpUnitAiEvals\Conversation\Turn;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Vcr\Cassette;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

#[AiVcr(cassette: 'conversation', mode: VcrMode::Auto)]
final class ConversationTest extends TestCase
{
    use AiAssertionsTrait;
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

    public function testEachTurnSeesTheHistoryAndAssertsOnItsReply(): void
    {
        $backend = new FakeClient(...array_map(self::reply(...), ['Which order?', 'Refund issued for #404.']));
        $seen = [];

        $conversation = $this->runConversation('refunds', $this->agent($this->wrapHttpClient($backend)), [
            Turn::user('I want a refund', static function (string $reply, Conversation $c) use (&$seen): void {
                $seen[] = count($c->messages());
                self::assertStringContainsString('order', $reply);
            }),
            Turn::user('Order #404', static function (string $reply, Conversation $c) use (&$seen): void {
                $seen[] = count($c->messages());
                self::assertSame('Which order?', $c->turn(1)->reply);
                self::assertCount(1, $c->turn(2)->interactions);
                self::assertStringContainsString('#404', $reply);
            }),
        ]);

        self::assertSame([2, 4], $seen);
        self::assertSame('Refund issued for #404.', $conversation->lastReply());
        self::assertCount(2, $conversation->interactions());
        // The agent was given the history: the second request carried all three earlier messages.
        $sent = Payload::maps(Payload::get(json_decode((string) $backend->requests[1]->getBody(), true), 'messages'));
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($sent, 'role'));
    }

    public function testEditingOneTurnReRecordsOnlyThatTurn(): void
    {
        $script = static fn(string $second): array => [Turn::user('Hi'), Turn::user($second), Turn::user('Thanks')];

        $this->play($script('Order #404'), 'Hello!', 'Looking up #404.', 'You are welcome.');
        $this->play($script('Order #404')); // replays fully: the backend has nothing queued

        $backend = $this->play($script('Order #505'), 'Looking up #505.');
        self::assertCount(1, $backend->requests, 'only the edited turn reached the provider');

        $turns = array_map(
            static fn(array $i): string => Payload::string(Payload::get($i, 'turn', 'conversation')) . '#' . Payload::int(Payload::get($i, 'turn', 'index')),
            $this->cassette()->interactions(),
        );
        self::assertSame(['support#1', 'support#2', 'support#3', 'support#2'], $turns);
    }

    public function testToolRoundsWithinATurnAreScopedToThatTurn(): void
    {
        $agent = function (ClientInterface $client): \Closure {
            return static function (Conversation $c) use ($client): string {
                $messages = [['role' => 'system', 'content' => 'Use tools.'], ...$c->messages()];
                $first = json_decode((string) $client->sendRequest(FakeClient::request(self::LLM, ['model' => 'm', 'messages' => $messages]))->getBody(), true);
                $messages[] = ['role' => 'assistant', 'content' => Payload::string(Payload::get($first, 'choices', 0, 'message', 'content'))];
                $messages[] = ['role' => 'tool', 'content' => 'order 404: shipped'];
                $second = json_decode((string) $client->sendRequest(FakeClient::request(self::LLM, ['model' => 'm', 'messages' => $messages]))->getBody(), true);

                return Payload::string(Payload::get($second, 'choices', 0, 'message', 'content'));
            };
        };
        $turns = [Turn::user('Where is #404?'), Turn::user('And #405?')];

        $this->runConversation('tools', $agent($this->wrapHttpClient(new FakeClient(...array_map(self::reply(...), ['checking', 'shipped', 'checking', 'not found'])))), $turns);
        $this->resetAiEvalState();
        $replayed = $this->runConversation('tools', $agent($this->wrapHttpClient(new FakeClient())), $turns);

        self::assertSame('not found', $replayed->lastReply());
        $second = Payload::maps(Payload::get($this->cassette()->interactions()[3], 'request', 'normalized_payload', 'messages'));
        self::assertSame(['system', 'user', 'assistant', 'tool'], array_column($second, 'role'), 'earlier turns are cut, the system prompt and this turn\'s rounds are kept');
    }

    public function testEditingTheSystemPromptStillMisses(): void
    {
        $this->play([Turn::user('Hi'), Turn::user('Bye')], 'Hello!', 'Goodbye!');

        $this->resetAiEvalState();
        $backend = new FakeClient(self::reply('Hey.'), self::reply('Ciao.'));
        $this->runConversation('support', $this->agent($this->wrapHttpClient($backend), 'You are a terse agent.'), [Turn::user('Hi'), Turn::user('Bye')]);

        self::assertCount(2, $backend->requests);
    }

    public function testAFailingTurnIsNamedInTheFailure(): void
    {
        try {
            $this->runConversation('refunds', $this->agent($this->wrapHttpClient(new FakeClient(self::reply('Sure.'), self::reply('Nope.')))), [
                Turn::user('Hi'),
                Turn::user('Refund #404', static fn(string $reply) => self::assertStringContainsString('refund', $reply)),
            ]);
            self::fail('expected the second turn to fail');
        } catch (AssertionFailedError $e) {
            self::assertStringStartsWith("Turn 2 of conversation \"refunds\" failed.\nUser: \"Refund #404\"\nReply: \"Nope.\"", $e->getMessage());
            self::assertStringContainsString("contains \"refund\"", $e->getMessage());
        }
    }

    /**
     * @param list<Turn> $turns
     */
    private function play(array $turns, string ...$replies): FakeClient
    {
        $this->resetAiEvalState();
        $backend = new FakeClient(...array_map(self::reply(...), $replies));
        $this->runConversation('support', $this->agent($this->wrapHttpClient($backend)), $turns);

        return $backend;
    }

    /**
     * @return \Closure(Conversation): ?string
     */
    private function agent(ClientInterface $client, string $system = 'You are a support agent.'): \Closure
    {
        return static function (Conversation $c) use ($client, $system): ?string {
            $client->sendRequest(FakeClient::request(self::LLM, [
                'model' => 'gpt-6-luna',
                'messages' => [['role' => 'system', 'content' => $system], ...$c->messages()],
            ]));

            return null; // the trace's last reply is used
        };
    }

    private static function reply(string $text): \Nyholm\Psr7\Response
    {
        return FakeClient::json(FakeClient::openAiReply($text));
    }

    private function cassette(): Cassette
    {
        $storage = new CassetteStorage($this->tmp);

        return $storage->load($storage->pathFor('conversation')) ?? self::fail('no cassette');
    }
}
