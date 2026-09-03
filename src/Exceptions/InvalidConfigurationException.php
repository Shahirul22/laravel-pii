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
}
