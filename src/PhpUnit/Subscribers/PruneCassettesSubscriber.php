<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers;

use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use PHPUnit\Event\Application\Finished;
use PHPUnit\Event\Application\FinishedSubscriber;
use RuntimeException;

/**
 * Registered when AI_EVAL_PRUNE is set: after a green run, removes the interactions no test used from every
 * cassette the run touched. Run it on the full suite; a filtered run would prune entries its tests never reached.
 *
 * @internal
 */
final class PruneCassettesSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        if ($event->shellExitCode() !== 0) {
            return;
        }

        $removed = 0;
        foreach (TestContextRegistry::used() as $path => $keys) {
            try {
                $removed += (new CassetteStorage(dirname($path)))->prune($path, $keys);
            } catch (RuntimeException $e) {
                // PHPUnit drops exceptions thrown after the run, so report it instead.
                print "AI Evals: could not prune {$path}: {$e->getMessage()}\n";
            }
        }

        print sprintf("AI Evals: pruned %d unused interaction(s) from %d cassette(s).\n", $removed, count(TestContextRegistry::used()));
    }
}
