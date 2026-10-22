<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Support;

use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\Cassette;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;

/**
 * A fresh AiVcrClient per call over one cassette in a temp dir; each call stands for a new test run.
 */
trait VcrTestTrait
{
    use TempDirTrait;

    private AiTraceCollector $collector;

    private CassetteStorage $storage;

    private function client(FakeClient $inner, VcrMode $mode): AiVcrClient
    {
        $this->collector = new AiTraceCollector();
        $this->storage = new CassetteStorage($this->tmp);

        return new AiVcrClient($inner, $this->storage, $this->storage->pathFor('T::t'), 'App\\T::t', $mode, $this->collector);
    }

    /**
     * @return array<string, mixed>
     */
    private function prompt(string $text): array
    {
        return ['model' => 'gpt-6-luna', 'messages' => [['role' => 'user', 'content' => $text]], 'temperature' => 0.3];
    }

    private function cassette(): Cassette
    {
        return $this->storage->load($this->storage->pathFor('T::t')) ?? self::fail('cassette not written');
    }
}
