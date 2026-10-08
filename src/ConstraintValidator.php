<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;

/**
 * Validates a generated replacement value against a column's schema
 * constraints before it is written — see docs/design/engine-hardening/spec
 * §R6. Every violation raises a named exception; nothing is ever coerced.
 */
final class ConstraintValidator
{
    /**
     * @throws ConstraintViolationException
     */
    public function assertWritable(string $modelClass, string $table, ColumnConstraints $constraints, mixed $value): void
    {
        if ($value === null) {
            if (! $constraints->nullable) {
                throw ConstraintViolationException::notNullable($modelClass, $constraints->column, $table);
            }

            // null passes every other check once nullability itself passes.
            return;
        }

        if (! $this->matchesFamily($constraints->family, $value)) {
            throw ConstraintViolationException::typeMismatch($modelClass, $constraints->column, $table, $constraints->family);
        }

        if ($constraints->allowed !== null && is_scalar($value)) {
            if ($constraints->family === 'set') {
                $stringValue = (string) $value;
                $members = $stringValue === '' ? [] : explode(',', $stringValue);

                foreach ($members as $member) {
                    if (! in_array($member, $constraints->allowed, true)) {
                        throw ConstraintViolationException::notInAllowedSet($modelClass, $constraints->column, $table, $constraints->allowed);
                    }
                }
            } elseif (! in_array((string) $value, $constraints->allowed, true)) {
                throw ConstraintViolationException::notInAllowedSet($modelClass, $constraints->column, $table, $constraints->allowed);
            }
        }

        if ($constraints->maxLength !== null && is_scalar($value)) {
            $length = mb_strlen((string) $value);

            if ($length > $constraints->maxLength) {
                throw ConstraintViolationException::tooLong($modelClass, $constraints->column, $table, $constraints->maxLength, $length);
            }
        }
    }

    /**
     * json_validate() is PHP 8.3+; the package's PHP floor is 8.2, so JSON
     * validity is checked via json_decode() + json_last_error() instead.
     */
    private function isValidJson(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function matchesFamily(string $family, mixed $value): bool
    {
        return match ($family) {
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'decimal' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
            'datetime' => is_string($value) || is_int($value),
            'json' => is_array($value) || (is_string($value) && $this->isValidJson($value)),
            'string', 'set' => is_scalar($value),
            default => true,
        };
    }
}
