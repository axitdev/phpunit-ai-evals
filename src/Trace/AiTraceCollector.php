<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace;

use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;

/**
 * Interactions of the currently running test. Static access lets the static assertions default to it.
 *
 * @internal
 */
final class AiTraceCollector
{
    private static ?self $current = null;

    /** @var list<AiInteraction> */
    private array $interactions = [];

    public static function current(): self
    {
        return self::$current ??= new self();
    }

    public function record(AiInteraction $interaction, bool $replayed = false): void
    {
        $this->interactions[] = $interaction;
        TestContextRegistry::recordInteraction($interaction, $replayed);
    }

    /**
     * @return list<AiInteraction>
     */
    public function all(): array
    {
        return $this->interactions;
    }

    public function last(): ?AiInteraction
    {
        return $this->interactions === [] ? null : $this->interactions[count($this->interactions) - 1];
    }

    public function clear(): void
    {
        $this->interactions = [];
    }
}
