<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers;

use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * @internal
 */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        TestContextRegistry::endTest();
    }
}
