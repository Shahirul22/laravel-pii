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

        if (! $this->matchesFamily($constraints, $value)) {
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

    private function matchesFamily(ColumnConstraints $constraints, mixed $value): bool
    {
        return match ($constraints->family) {
            'integer' => $this->isInteger($value),
            'decimal' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true) || $this->fitsTinyintOne($constraints, $value),
            'datetime' => is_string($value) || is_int($value),
            'json' => is_array($value) || (is_string($value) && $this->isValidJson($value)),
            'string', 'set' => is_scalar($value),
            default => true,
        };
    }

    private function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }

    /**
     * MySQL and MariaDB report a BOOLEAN column as tinyint(1), so the schema
     * cannot tell a boolean from a legacy tinyint(1) that stores small codes
     * (0, 1, 2, ...): the display width (1) does not limit the stored value.
     * The rule: a column whose native type is exactly tinyint(1) accepts a
     * boolean and also any integer in the signed tinyint range -128 to 127,
     * which is everything the database itself accepts there. A real boolean
     * type (PostgreSQL boolean, for example) still accepts only booleans and
     * 0/1.
     */
    private function fitsTinyintOne(ColumnConstraints $constraints, mixed $value): bool
    {
        if ($constraints->nativeType === null || strtolower($constraints->nativeType) !== 'tinyint(1)' || ! $this->isInteger($value)) {
            return false;
        }

        $integer = (int) $value;

        return $integer >= -128 && $integer <= 127;
    }
}
