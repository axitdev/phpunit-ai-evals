<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use LogicException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Evaluates a JSON string against a JSON Schema (draft 2020-12 and earlier). Needs opis/json-schema.
 */
final class MatchesJsonSchemaConstraint extends Constraint
{
    private string $error = '';

    /**
     * @param array<string, mixed>|string $schema Schema as an array or a JSON string
     */
    public function __construct(private readonly array|string $schema)
    {
        if (!class_exists(Validator::class)) {
            throw new LogicException('assertMatchesJsonSchema() needs opis/json-schema: composer require --dev opis/json-schema');
        }
    }

    public function toString(): string
    {
        return 'matches the JSON schema';
    }

    protected function matches(mixed $other): bool
    {
        $data = json_decode(is_string($other) ? $other : '');
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = 'Not valid JSON: ' . json_last_error_msg();

            return false;
        }

        $schema = is_string($this->schema) ? $this->schema : (string) json_encode($this->schema);
        $result = (new Validator())->validate($data, $schema);
        $error = $result->error();
        $this->error = $error === null ? '' : (string) json_encode((new ErrorFormatter())->format($error), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $result->isValid();
    }

    protected function failureDescription(mixed $other): string
    {
        return 'the output ' . $this->toString();
    }

    protected function additionalFailureDescription(mixed $other): string
    {
        return $this->error;
    }
}
