<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use JsonException;
use UnexpectedValueException;

/**
 * @internal
 */
final class Cassette
{
    public const VERSION = '1.1';

    public const KIND_AGENT = 'agent_call';

    public const KIND_JUDGE = 'judge_eval';

    public const KIND_RAW = 'raw_http';

    /**
     * @param list<array<string, mixed>> $interactions
     */
    public function __construct(
        public readonly string $test,
        private array $interactions = [],
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function interactions(): array
    {
        return $this->interactions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $kind, string $hash, int $occurrence): ?array
    {
        foreach ($this->interactions as $interaction) {
            if ($this->matches($interaction, $kind, $hash, $occurrence)) {
                return $interaction;
            }
        }

        return null;
    }

    public function hasHash(string $kind, string $hash): bool
    {
        foreach ($this->interactions as $interaction) {
            if (($interaction['kind'] ?? null) === $kind && ($interaction['semantic_hash'] ?? null) === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $interaction
     */
    private function upsert(array $interaction): void
    {
        $kind = Payload::string($interaction['kind'] ?? null);
        $hash = Payload::string($interaction['semantic_hash'] ?? null);
        $occurrence = Payload::int($interaction['occurrence_index'] ?? null);

        foreach ($this->interactions as $i => $existing) {
            if ($this->matches($existing, $kind, $hash, $occurrence)) {
                $this->interactions[$i] = $interaction;

                return;
            }
        }
        $this->interactions[] = $interaction;
    }

    /**
     * Drops every interaction whose key() is not in $keys; returns how many were removed.
     *
     * @param array<string, true> $keys
     */
    public function retain(array $keys): int
    {
        $before = count($this->interactions);
        $this->interactions = array_values(array_filter(
            $this->interactions,
            static fn(array $i): bool => isset($keys[self::key(
                Payload::string($i['kind'] ?? null),
                Payload::string($i['semantic_hash'] ?? null),
                Payload::int($i['occurrence_index'] ?? null),
            )]),
        ));

        return $before - count($this->interactions);
    }

    public static function key(string $kind, string $hash, int $occurrence): string
    {
        return $kind . ':' . $hash . ':' . $occurrence;
    }

    public function merge(self $other): void
    {
        foreach ($other->interactions as $interaction) {
            $this->upsert($interaction);
        }
    }

    /**
     * Normalized payload of the recorded interaction (same kind) sharing the most lines with $payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function closestPayload(string $kind, array $payload): ?array
    {
        $needle = self::lines($payload);
        $best = null;
        $bestScore = -1;
        foreach ($this->interactions as $interaction) {
            if (($interaction['kind'] ?? null) !== $kind) {
                continue;
            }
            $candidate = Payload::map(Payload::get($interaction, 'request', 'normalized_payload'));
            $score = count(array_intersect(self::lines($candidate), $needle));
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function toJson(): string
    {
        return json_encode([
            'version' => self::VERSION,
            'test' => $this->test,
            'interactions' => $this->interactions,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Trailing commas are dropped so moving the last key doesn't show up as a change.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function lines(array $payload): array
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return array_map(static fn(string $line): string => rtrim($line, ','), explode("\n", $json === false ? '' : $json));
    }

    /**
     * A cassette without `version` (e.g. hand-written) is read as the current one; another major version is refused.
     *
     * @throws JsonException
     * @throws UnexpectedValueException
     */
    public static function fromJson(string $json): self
    {
        $data = Payload::map(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $version = Payload::string($data['version'] ?? null);
        if ($version !== '' && explode('.', $version)[0] !== explode('.', self::VERSION)[0]) {
            throw new UnexpectedValueException(sprintf(
                'Cassette format %s is not supported (this version reads %s). Re-record it, or use the release that wrote it.',
                $version,
                self::VERSION,
            ));
        }

        return new self(Payload::string($data['test'] ?? null), Payload::maps($data['interactions'] ?? null));
    }

    /**
     * @param array<string, mixed> $interaction
     */
    private function matches(array $interaction, string $kind, string $hash, int $occurrence): bool
    {
        return ($interaction['kind'] ?? null) === $kind
            && ($interaction['semantic_hash'] ?? null) === $hash
            && ($interaction['occurrence_index'] ?? null) === $occurrence;
    }
}
