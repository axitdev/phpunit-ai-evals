<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use InvalidArgumentException;

final readonly class JudgeSettings
{
    public function __construct(
        public string $baseUri,
        public string $model,
        public string $apiKey,
        public ?float $temperature = null,
        public bool $jsonMode = true,
        public JudgeProvider $provider = JudgeProvider::OpenAiCompatible,
    ) {}

    /**
     * Order: #[AiJudgeConfig] attribute, then AI_EVAL_JUDGE_* environment, then the provider's defaults.
     * A vendor key (OPENAI_API_KEY, TYPESAFE_API_KEY) is only a fallback for that vendor's host, so it is never sent to
     * another one.
     */
    public static function resolve(?AiJudgeConfig $attribute = null): self
    {
        $providerName = $attribute->provider ?? self::env('AI_EVAL_JUDGE_PROVIDER');
        $provider = $providerName === null ? JudgeProvider::OpenAiCompatible : JudgeProvider::fromName($providerName);
        $baseUri = $attribute->baseUri ?? self::env('AI_EVAL_JUDGE_BASE_URI') ?? $provider->defaultBaseUri();
        [$vendorHost, $vendorKey] = $provider->vendorKey();
        $isVendor = strtolower((string) parse_url($baseUri, PHP_URL_HOST)) === $vendorHost;

        return new self(
            $baseUri,
            $attribute->model ?? self::env('AI_EVAL_JUDGE_MODEL') ?? $provider->defaultModel(),
            self::env('AI_EVAL_JUDGE_API_KEY') ?? ($isVendor ? self::env($vendorKey) : null) ?? '',
            $attribute->temperature ?? self::envFloat('AI_EVAL_JUDGE_TEMPERATURE'),
            $attribute->jsonMode ?? self::envBool('AI_EVAL_JUDGE_JSON_MODE') ?? true,
            $provider,
        );
    }

    private static function envFloat(string $name): ?float
    {
        $value = self::env($name);
        if ($value !== null && !is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Invalid %s "%s"; expected a number such as 0 or 0.2.', $name, $value));
        }

        return $value === null ? null : (float) $value;
    }

    private static function envBool(string $name): ?bool
    {
        $value = self::env($name);

        return match (strtolower($value ?? '')) {
            '' => null,
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => throw new InvalidArgumentException(sprintf('Invalid %s "%s"; expected 1, true, on or yes, or 0, false, off or no.', $name, $value)),
        };
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
