<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\MatchingRules;
use Axitdev\PhpUnitAiEvals\Vcr\PromptNormalizer;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use InvalidArgumentException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class MatchingRulesTest extends TestCase
{
    use TempDirTrait;

    private const URI = 'https://api.openai.com/v1/chat/completions';

    public function testIgnoreRemovesFieldsAtEveryWildcardMatch(): void
    {
        $rules = new MatchingRules(['model', 'messages.*.name']);

        self::assertSame(
            ['messages' => [['role' => 'user'], ['role' => 'assistant']]],
            $rules->apply(['model' => 'm', 'messages' => [['role' => 'user', 'name' => 'a'], ['role' => 'assistant', 'name' => 'b']]]),
        );
    }

    public function testNormalizeKeepsTheFieldButNotItsValue(): void
    {
        $rules = new MatchingRules(normalizeFields: ['metadata.request_id', 'user' => '<USER>']);

        self::assertSame(
            ['metadata' => ['request_id' => '<NORMALIZED>'], 'user' => '<USER>'],
            $rules->apply(['metadata' => ['request_id' => 'req_8f2a'], 'user' => 'u-1']),
        );
        self::assertSame(['other' => 1], $rules->apply(['other' => 1]), 'absent fields stay absent');
    }

    public function testRemovingAListElementKeepsAList(): void
    {
        self::assertSame(['messages' => [['b' => 2]]], (new MatchingRules(['messages.0']))->apply(['messages' => [['a' => 1], ['b' => 2]]]));
    }

    public function testMergeAddsListsAndLetsTheLaterPlaceholderWin(): void
    {
        $merged = (new MatchingRules(['model'], ['user' => '<A>'], ['X-Tenant']))
            ->merge(new MatchingRules(['seed'], ['user' => '<B>'], ['x-tenant', 'X-Region'], ['temperature']));

        self::assertSame(['model', 'seed'], $merged->ignoreFields);
        self::assertSame(['user' => '<B>'], $merged->normalizeFields);
        self::assertSame(['x-tenant', 'x-region'], $merged->matchHeaders);
        self::assertSame(['temperature'], $merged->matchFields);
    }

    public function testRejectsMalformedPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MatchingRules(['messages..content']);
    }

    public function testIgnoringTheModelReusesRecordingsAcrossModels(): void
    {
        $rules = new MatchingRules(['model']);
        $this->vcr(VcrMode::Record, $rules, FakeClient::json(FakeClient::openAiReply('hi')))->sendRequest($this->request('gpt-6-luna', 'Hello'));

        $reply = $this->vcr(VcrMode::Replay, $rules)->sendRequest($this->request('gpt-6-sol', 'Hello'));
        self::assertStringContainsString('hi', (string) $reply->getBody());
        self::assertArrayNotHasKey('model', Payload::map(Payload::get($this->storage()->load($this->path())?->interactions()[0], 'request', 'normalized_payload')));
    }

    public function testTemperatureIsIgnoredByDefaultAndCanBeMatched(): void
    {
        $this->vcr(VcrMode::Record, new MatchingRules(), FakeClient::json(FakeClient::openAiReply('a')))->sendRequest($this->request('m', 'q', 0.1));
        $this->vcr(VcrMode::Replay, new MatchingRules())->sendRequest($this->request('m', 'q', 0.9));

        $this->vcr(VcrMode::Record, new MatchingRules(matchFields: ['temperature']), FakeClient::json(FakeClient::openAiReply('b')))->sendRequest($this->request('m', 'q', 0.1));
        $replay = $this->vcr(VcrMode::Replay, new MatchingRules(matchFields: ['temperature']));
        self::assertStringContainsString('"b"', (string) $replay->sendRequest($this->request('m', 'q', 0.1))->getBody());
        $this->expectExceptionMessage("  temperature\n    recorded: ");
        $replay->sendRequest($this->request('m', 'q', 0.9));
    }

    public function testMatchedHeadersJoinTheHashAndCanBeNormalized(): void
    {
        $rules = new MatchingRules(normalizeFields: ['headers.x-request-id'], matchHeaders: ['X-Tenant', 'X-Request-Id']);
        $this->vcr(VcrMode::Record, $rules, FakeClient::json(FakeClient::openAiReply('a')))
            ->sendRequest($this->request('m', 'q')->withHeader('X-Tenant', 'acme')->withHeader('X-Request-Id', 'r1'));

        $stored = Payload::get($this->storage()->load($this->path())?->interactions()[0], 'request', 'normalized_payload', 'headers');
        self::assertSame(['x-request-id' => '<NORMALIZED>', 'x-tenant' => 'acme'], $stored);

        $this->vcr(VcrMode::Replay, $rules)->sendRequest($this->request('m', 'q')->withHeader('X-Tenant', 'acme')->withHeader('X-Request-Id', 'r2'));

        try {
            $this->vcr(VcrMode::Replay, $rules)->sendRequest($this->request('m', 'q')->withHeader('X-Tenant', 'globex')->withHeader('X-Request-Id', 'r3'));
            self::fail('expected a miss');
        } catch (CassetteMissException $e) {
            self::assertStringContainsString('Differs from the closest recorded interaction in: headers.x-tenant', $e->getMessage());
        }
    }

    public function testRulesApplyToRawJsonBodies(): void
    {
        $rules = new MatchingRules(['body.json.sent_at'], ['body.json.trace' => '<TRACE>']);
        $raw = static fn(string $at, string $trace, int $page): Request => FakeClient::request('https://crm.test/search', ['sent_at' => $at, 'trace' => $trace, 'page' => $page]);
        $this->vcr(VcrMode::Record, $rules, FakeClient::json(['hits' => 1]))->sendRequest($raw('noon', 't1', 1));

        self::assertSame('{"hits":1}', (string) $this->vcr(VcrMode::Replay, $rules)->sendRequest($raw('midnight', 't2', 1))->getBody());
        $this->expectExceptionMessage('in: body.json.page');
        $this->vcr(VcrMode::Replay, $rules)->sendRequest($raw('noon', 't1', 2));
    }

    public function testAttributeBuildsRules(): void
    {
        $rules = (new AiVcr(ignoreFields: ['model'], normalizeFields: ['user'], matchHeaders: ['X-A'], matchFields: ['seed']))->matchingRules();

        self::assertSame(['model'], $rules->ignoreFields);
        self::assertSame(['user' => '<NORMALIZED>'], $rules->normalizeFields);
        self::assertSame(['x-a'], $rules->matchHeaders);
        self::assertSame(['seed'], $rules->matchFields);
    }

    private function vcr(VcrMode $mode, MatchingRules $rules, \Psr\Http\Message\ResponseInterface ...$responses): AiVcrClient
    {
        AiTraceCollector::current()->clear();

        return new AiVcrClient(
            new FakeClient(...$responses),
            $this->storage(),
            $this->path(),
            'App\\T::t',
            $mode,
            AiTraceCollector::current(),
            new PromptNormalizer(rules: $rules),
        );
    }

    private function request(string $model, string $text, float $temperature = 0.3): Request
    {
        return FakeClient::request(self::URI, ['model' => $model, 'messages' => [['role' => 'user', 'content' => $text]], 'temperature' => $temperature]);
    }

    private function storage(): CassetteStorage
    {
        return new CassetteStorage($this->tmp);
    }

    private function path(): string
    {
        return $this->storage()->pathFor('T::t');
    }
}
