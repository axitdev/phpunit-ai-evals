<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use InvalidArgumentException;

enum JudgeProvider: string
{
    /** Any OpenAI Chat Completions endpoint: OpenAI, Claude, Gemini, Ollama, vLLM... */
    case OpenAiCompatible = 'openai';

    /** A System One model (TypeSafe Jev): calibrated yes/no probabilities instead of a generated grade. */
    case SystemOne = 'system-one';

    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower(trim($name))) ?? throw new InvalidArgumentException(sprintf(
            'Unknown judge provider "%s"; expected one of: %s.',
            $name,
            implode(', ', array_map(static fn(self $p): string => $p->value, self::cases())),
        ));
    }

    public function defaultBaseUri(): string
    {
        return match ($this) {
            self::OpenAiCompatible => 'https://api.openai.com/v1',
            self::SystemOne => 'https://api.typesafe.ai/v1',
        };
    }

    public function defaultModel(): string
    {
        return match ($this) {
            self::OpenAiCompatible => 'gpt-6-luna',
            self::SystemOne => 'jev-latest',
        };
    }

    /**
     * The vendor's own key variable, read only when the base URI is the vendor's host so it never goes elsewhere.
     *
     * @return array{string, string} host, environment variable
     */
    public function vendorKey(): array
    {
        return match ($this) {
            self::OpenAiCompatible => ['api.openai.com', 'OPENAI_API_KEY'],
            self::SystemOne => ['api.typesafe.ai', 'TYPESAFE_API_KEY'],
        };
    }
}
