<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Attributes;

use Attribute;
use Axitdev\PhpUnitAiEvals\Vcr\MatchingRules;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AiVcr
{
    /**
     * @param VcrMode|null $mode Null falls back to the extension's `mode` parameter, then Auto
     * @param list<string> $ignorePatterns Extra PCRE patterns scrubbed from prompts before hashing
     * @param bool|null $ignoreDatesAndUuids False keeps dates and UUIDs in the hash, for prompts that are about them.
     *                                       Null falls back to the class, then true
     * @param list<string> $ignoreFields Request fields left out of matching, as dot paths: `model`, `messages.*.name`
     * @param array<int|string, string> $normalizeFields Fields that must be present but may hold any value:
     *                                                   `['metadata.request_id']` or `['user' => '<USER>']`
     * @param list<string> $matchHeaders Request headers that join the match, e.g. `['X-Tenant']`; none by default
     * @param list<string> $matchFields Request fields matched on top of the built-in ones, e.g. `['temperature']`
     *
     * The four field options add up across the extension parameters, the class and the method.
     */
    public function __construct(
        public ?string $cassette = null,
        public ?VcrMode $mode = null,
        public array $ignorePatterns = [],
        public ?bool $ignoreDatesAndUuids = null,
        public array $ignoreFields = [],
        public array $normalizeFields = [],
        public array $matchHeaders = [],
        public array $matchFields = [],
    ) {}

    public function matchingRules(): MatchingRules
    {
        return new MatchingRules($this->ignoreFields, $this->normalizeFields, $this->matchHeaders, $this->matchFields);
    }
}
