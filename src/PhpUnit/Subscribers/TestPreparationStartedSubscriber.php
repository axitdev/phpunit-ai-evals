<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers;

use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * @internal
 */
final class TestPreparationStartedSubscriber implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        TestContextRegistry::startTest($event->test()->id());
    }
}
