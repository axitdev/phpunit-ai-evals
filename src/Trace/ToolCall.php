<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace;

final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
    ) {}
}
