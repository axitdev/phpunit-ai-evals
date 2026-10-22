<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AiJudgeConfig
{
    public function __construct(
        public ?string $model = null,
        public ?string $baseUri = null,
        /** Null leaves it out of the request (some models reject it). Null: AI_EVAL_JUDGE_TEMPERATURE, then omitted. */
        public ?float $temperature = null,
        /** Send `response_format: json_object`; turn off for endpoints that reject it. Null: AI_EVAL_JUDGE_JSON_MODE, then on. */
        public ?bool $jsonMode = null,
    ) {}
}
