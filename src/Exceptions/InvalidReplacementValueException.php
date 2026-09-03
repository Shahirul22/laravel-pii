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
}
