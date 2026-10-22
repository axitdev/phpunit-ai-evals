<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Attributes;

use Attribute;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AiVcr
{
    /**
     * @param VcrMode|null $mode Null falls back to the extension's `mode` parameter, then Auto
     * @param list<string> $ignorePatterns Extra PCRE patterns scrubbed from prompts before hashing
     * @param bool|null $ignoreDatesAndUuids False keeps dates and UUIDs in the hash, for prompts that are about them.
     *                                       Null falls back to the class, then true
     */
    public function __construct(
        public ?string $cassette = null,
        public ?VcrMode $mode = null,
        public array $ignorePatterns = [],
        public ?bool $ignoreDatesAndUuids = null,
    ) {}
}
