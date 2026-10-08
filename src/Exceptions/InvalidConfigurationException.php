<?php

namespace Shahirul22\LaravelPiiSanitizer\Exceptions;

final class InvalidConfigurationException extends \RuntimeException
{
    public static function invalidModelClass(string $value): self
    {
        return new self(
            "[laravel-pii-sanitizer] \"{$value}\" in pii.models (or --model) is not a valid Eloquent model class. Check the class exists and extends Illuminate\\Database\\Eloquent\\Model."
        );
    }

    public static function modelsNotAList(): self
    {
        return new self(
            '[laravel-pii-sanitizer] pii.models (or the models passed to RunOptions) must be a list of class-name strings.'
        );
    }

    public static function invalidSanitizerClass(string $value): self
    {
        return new self(
            "[laravel-pii-sanitizer] \"{$value}\" in pii.sanitizers is not a valid Sanitizer class. Check the class exists and extends Shahirul22\\LaravelPiiSanitizer\\Sanitizer."
        );
    }

    public static function tablesNotAMap(): self
    {
        return new self(
            '[laravel-pii-sanitizer] pii.tables must be a map of table-name => Sanitizer class-name strings.'
        );
    }

    public static function missingKeyedKey(): self
    {
        return new self(
            '[laravel-pii-sanitizer] A Keyed value-definition is declared but no usable key is configured. Set PII_SANITIZER_KEY (read via pii.keyed.key) to at least 32 bytes, raw or "base64:"-prefixed.'
        );
    }

    public static function keyedNamespaceShapeMismatch(string $namespace): self
    {
        return new self(
            "[laravel-pii-sanitizer] Keyed namespace \"{$namespace}\" is declared with different shapes. Every Keyed::using() or Keyed::pattern() in one namespace must use an identical shape and parameters, or the same input would map to different values."
        );
    }
}
