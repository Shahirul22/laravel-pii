<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class InvalidReplacementValueException extends \RuntimeException
{
    public static function unsupportedType(string $column, string $type): self
    {
        return new self(
            "[laravel-pii-sanitizer] The value definition for \${$column} resolved to a {$type}, which cannot be written to a database column. Return a scalar, null, array, or enum from the closure/ValueGenerator/Faker call — not an object such as DateTime."
        );
    }

    public static function unsupportedInput(string $definition, string $type): self
    {
        return new self(
            "[laravel-pii-sanitizer] The {$definition} value-definition cannot use an input of type {$type}. Supported inputs are null, int, string, bool, a backed enum, or a Stringable."
        );
    }

    public static function inColumn(string $column, self $previous): self
    {
        $inner = preg_replace('/^\[laravel-pii-sanitizer\] /', '', $previous->getMessage()) ?? $previous->getMessage();

        return new self("[laravel-pii-sanitizer] \${$column}: {$inner}", 0, $previous);
    }
}
