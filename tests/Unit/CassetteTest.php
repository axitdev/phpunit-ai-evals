<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Vcr\Cassette;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use PHPUnit\Framework\TestCase;

final class CassetteTest extends TestCase
{
    use TempDirTrait;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function entry(string $hash, int $index, string $marker = 'a', string $kind = 'agent_call', array $payload = []): array
    {
        return [
            'semantic_hash' => $hash,
            'occurrence_index' => $index,
            'kind' => $kind,
            'request' => ['normalized_payload' => $payload],
            'marker' => $marker,
        ];
    }

    public function testUpsertReplacesByKindHashAndOccurrence(): void
    {
        $c = new Cassette('T::t');
        foreach ([self::entry('h', 0, 'first'), self::entry('h', 1, 'second'), self::entry('h', 0, 'replaced'), self::entry('h', 0, 'judge', 'judge_eval')] as $entry) {
            $c->merge(new Cassette('T::t', [$entry]));
        }

        self::assertCount(3, $c->interactions());
        self::assertSame('replaced', Payload::get($c->find('agent_call', 'h', 0), 'marker'));
        self::assertSame('second', Payload::get($c->find('agent_call', 'h', 1), 'marker'));
        self::assertNull($c->find('agent_call', 'h', 2));
        self::assertTrue($c->hasHash('agent_call', 'h'));
        self::assertFalse($c->hasHash('raw_http', 'h'));
    }

    public function testJsonRoundTripUsesSpecFormatting(): void
    {
        $c = new Cassette('App\\Tests\\T::t', [self::entry('h', 0, 'ünï/code')]);
        $json = $c->toJson();

        self::assertStringContainsString("\n    ", $json);
        self::assertStringContainsString('ünï/code', $json);
        self::assertStringContainsString('"version": "1.0"', $json);
        self::assertEquals($c, Cassette::fromJson($json));
    }

    public function testClosestPayloadPicksMostSimilarOfSameKind(): void
    {
        $c = new Cassette('t', [
            self::entry('1', 0, 'x', 'agent_call', ['messages' => [['content' => 'refund order 404']]]),
            self::entry('2', 0, 'x', 'agent_call', ['messages' => [['content' => 'weather in Paris']]]),
            self::entry('3', 0, 'x', 'judge_eval', ['messages' => [['content' => 'refund order 405']]]),
        ]);

        $closest = $c->closestPayload('agent_call', ['messages' => [['content' => 'refund order 405']]]);

        self::assertSame(['messages' => [['content' => 'refund order 404']]], $closest);
        self::assertNull((new Cassette('t'))->closestPayload('agent_call', []));

        $long = ['messages' => ['a', 'b', 'c', 'd', 'e', 'f']];
        $c = new Cassette('t', [self::entry('h1', 0, 'm', 'agent_call', $long), self::entry('h2', 0, 'm', 'agent_call', ['messages' => ['x', 'y']])]);
        self::assertSame(['messages' => ['x', 'y']], $c->closestPayload('agent_call', ['messages' => ['x', 'z']]), 'most shared lines wins, not the longest or first payload');
    }

    public function testMissExceptionShowsDiffAndRecordCommand(): void
    {
        $e = CassetteMissException::create(
            '/c/x.json',
            'App\\Tests\\RefundTest::testRefund',
            'agent_call',
            'abc',
            0,
            ['messages' => [['content' => 'refund 405 café/2']]],
            ['messages' => [['content' => 'refund 404 café/1']]],
            false,
        );

        self::assertStringContainsString('semantic_hash abc', $e->getMessage());
        self::assertStringContainsString("\nCassette: /c/x.json\n", $e->getMessage());
        self::assertStringContainsString('-             "content": "refund 404 café/1"', $e->getMessage(), 'unicode and slashes stay readable');
        self::assertStringContainsString('+             "content": "refund 405 café/2"', $e->getMessage());
        self::assertStringContainsString('AI_EVAL_MODE=record vendor/bin/phpunit --filter ' . escapeshellarg('RefundTest::testRefund\b'), $e->getMessage());
    }

    public function testMissExceptionExplainsExhaustedOccurrences(): void
    {
        $e = CassetteMissException::create('/c.json', 't', 'agent_call', 'abc', 2, [], null, true);

        self::assertStringContainsString('not occurrence #2', $e->getMessage());
        self::assertStringNotContainsString('Diff against', $e->getMessage());
    }

    public function testDiffOfReorderedLines(): void
    {
        $e = CassetteMissException::create('/c.json', 't', 'agent_call', 'abc', 0, ['b' => 2, 'a' => 1], ['a' => 1, 'b' => 2], false);

        self::assertStringContainsString("(- recorded, + current):\n(payloads differ only in line order)\n", $e->getMessage());
    }

    public function testOtherMajorCassetteVersionsAreRefusedAndMissingVersionIsAccepted(): void
    {
        self::assertSame('t', Cassette::fromJson('{"test": "t", "interactions": []}')->test);
        self::assertSame('t', Cassette::fromJson('{"version": "1.7", "test": "t"}')->test);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Cassette format 2.0 is not supported (this version reads 1.0)');
        Cassette::fromJson('{"version": "2.0", "test": "t"}');
    }

    public function testCorruptCassettesFailLoudly(): void
    {
        $this->expectException(\JsonException::class);
        Cassette::fromJson('{"version": "1.0", "interactions": [');
    }

    public function testStoragePathSanitizesNames(): void
    {
        $s = new CassetteStorage('/dir/');

        self::assertSame('/dir' . DIRECTORY_SEPARATOR . 'App_Tests_T_testIt.json', $s->pathFor('App\\Tests\\T::testIt'));
        self::assertSame('/dir' . DIRECTORY_SEPARATOR . 'custom.json', $s->pathFor('custom.json'));
    }

    public function testStorageMergeUpsertsAcrossWritersAndLeavesNoTempFiles(): void
    {
        $s = new CassetteStorage($this->tmp . '/nested/dir');
        $path = $s->pathFor('T::t');

        self::assertNull($s->load($path));
        $s->merge($path, new Cassette('T::t', [self::entry('h', 0, 'worker-1')]));
        $s->merge($path, new Cassette('T::t', [self::entry('h', 1, 'worker-2'), self::entry('h', 0, 'worker-1b')]));

        $loaded = (new CassetteStorage($this->tmp . '/nested/dir'))->load($path);
        self::assertNotNull($loaded);
        self::assertCount(2, $loaded->interactions());
        self::assertSame('worker-1b', Payload::get($loaded->find('agent_call', 'h', 0), 'marker'));
        self::assertSame('worker-2', Payload::get($loaded->find('agent_call', 'h', 1), 'marker'));
        self::assertSame([], glob($this->tmp . '/nested/dir/*.tmp'));
    }

    public function testPruneKeepsOnlyUsedInteractions(): void
    {
        $s = new CassetteStorage($this->tmp);
        $path = $s->pathFor('T::t');
        $s->merge($path, new Cassette('T::t', [self::entry('h', 0, 'used'), self::entry('h', 1, 'stale'), ['kind' => 'broken']]));

        self::assertSame(0, $s->prune($s->pathFor('missing'), []));
        self::assertSame(2, $s->prune($path, [Cassette::key('agent_call', 'h', 0) => true]));
        self::assertSame(0, $s->prune($path, [Cassette::key('agent_call', 'h', 0) => true]));

        $loaded = $s->load($path);
        self::assertSame(['used'], array_column($loaded?->interactions() ?? [], 'marker'));
        self::assertFileDoesNotExist($s->pathFor('missing'));
    }

    public function testKeysAndLookupsAreExact(): void
    {
        self::assertSame('agent_call:abc:2', Cassette::key('agent_call', 'abc', 2));

        $c = new Cassette('T::t', [self::entry('h', 0, 'other kind', 'judge_eval'), self::entry('h', 0, 'agent', 'agent_call', ['m' => 1])]);
        self::assertTrue($c->hasHash('agent_call', 'h'));
        self::assertFalse($c->hasHash('agent_call', 'other'));
        self::assertSame(['m' => 1], $c->closestPayload('agent_call', ['m' => 2]), 'entries of another kind are skipped, not the end of the search');

        $c->retain([Cassette::key('agent_call', 'h', 0) => true]);
        self::assertSame([0], array_keys($c->interactions()), 'kept entries are re-indexed');
        self::assertStringEndsWith("}\n", $c->toJson());
    }

    public function testLongTestNamesGetAShortenedUniqueCassetteThatCanBeWritten(): void
    {
        $s = new CassetteStorage($this->tmp);
        $long = 'App\\Tests\\' . str_repeat('VeryLongNamespace\\', 8) . 'SomeTest::test_' . str_repeat('it_does_something_', 8);
        $path = $s->pathFor($long . 'a');

        self::assertLessThanOrEqual(205, strlen(basename($path)));
        self::assertStringStartsWith('App_Tests_VeryLongNamespace_', basename($path));
        self::assertSame($path, $s->pathFor($long . 'a'));
        self::assertNotSame($path, $s->pathFor($long . 'b'));
        $s->merge($path, new Cassette('t', [self::entry('h', 0)]));
        self::assertFileExists($path);
    }

    public function testStoragePathsLocksAndUntouchedFiles(): void
    {
        $s = new CassetteStorage($this->tmp);
        self::assertSame($this->tmp . DIRECTORY_SEPARATOR . 'My_Test_name.json', $s->pathFor('__My Test::name__'));

        $path = $s->pathFor('T::t');
        $s->merge($path, new Cassette('T::t', [self::entry('h', 0)]));
        self::assertSame(['T_t.json'], array_map(basename(...), glob($this->tmp . '/*') ?: []), 'no lock file next to the cassette');
        self::assertFileExists(CassetteStorage::lockPath($path));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0o700, fileperms(dirname(CassetteStorage::lockPath($path))) & 0o777, 'lock folder is private to the user');
        }
        self::assertSame(CassetteStorage::lockPath($path), CassetteStorage::lockPath($this->tmp . '/./T_t.json'), 'the same file gets the same lock however it is spelled');

        $inode = fileinode($path);
        self::assertSame(0, $s->prune($path, [Cassette::key('agent_call', 'h', 0) => true]));
        self::assertSame($inode, fileinode($path), 'nothing to prune: the file is not rewritten');
    }

    public function testParallelWritersNeverLoseRecordings(): void
    {
        $path = (new CassetteStorage($this->tmp))->pathFor('shared');
        $script = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            [, , $path, $worker] = $argv;
            $storage = new Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage(dirname($path));
            for ($i = 0; $i < 25; $i++) {
                $storage->merge($path, new Axitdev\PhpUnitAiEvals\Vcr\Cassette('T::t', [
                    ['kind' => 'agent_call', 'semantic_hash' => "w{$worker}", 'occurrence_index' => $i],
                ]));
            }
            PHP;

        $workers = [];
        foreach (range(1, 4) as $worker) {
            $workers[] = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2), $path, (string) $worker], [], $pipes);
        }
        foreach ($workers as $process) {
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process));
        }

        self::assertCount(100, (new CassetteStorage($this->tmp))->load($path)?->interactions() ?? []);
    }

    public function testStorageExplainsAMissingDirectory(): void
    {
        file_put_contents($this->tmp . '/blocker', 'x');
        $s = new CassetteStorage($this->tmp . '/blocker/sub');

        $this->expectExceptionMessage('Cannot create cassette directory');
        @$s->merge($s->pathFor('t'), new Cassette('t'));
    }

    public function testOccurrenceCountsAreKeptApartPerKindAndHash(): void
    {
        $counter = new \Axitdev\PhpUnitAiEvals\Vcr\OccurrenceCounter();

        self::assertSame([0, 0, 0, 1], [$counter->next('ab', 'c'), $counter->next('a', 'bc'), $counter->next('x', 'y'), $counter->next('ab', 'c')]);
    }
}
