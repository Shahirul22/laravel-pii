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

    public static function inPath(string $path, self $previous): self
    {
        $inner = preg_replace('/^\[laravel-pii-sanitizer\] /', '', $previous->getMessage()) ?? $previous->getMessage();

        return new self("[laravel-pii-sanitizer] path {$path}: {$inner}", 0, $previous);
    }

    public static function invalidJsonDocument(): self
    {
        return new self(
            '[laravel-pii-sanitizer] The column value is not a valid JSON document, so its declared paths cannot be rewritten.'
        );
    }

    public static function nulByteJsonKey(): self
    {
        return new self(
            '[laravel-pii-sanitizer] The column value is a JSON document with an object key that starts with a NUL byte (\\u0000), which PHP cannot decode into an object, so its declared paths cannot be rewritten without changing the document. Give the column an array or json cast, which decodes it as an array, or remove that key.'
        );
    }

    public static function unsupportedJsonCarrier(string $type): self
    {
        return new self(
            "[laravel-pii-sanitizer] Json::paths() received a {$type} column value; expected a JSON string or an array."
        );
    }

    public static function unsupportedPathValue(string $type): self
    {
        return new self(
            "[laravel-pii-sanitizer] The value definition resolved to a {$type}, which cannot be written into JSON. Return null, a scalar, an array or an enum."
        );
    }
}
