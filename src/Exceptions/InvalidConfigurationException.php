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

    public static function invalidMirrorDeclaration(string $sanitizerClass, string $column, string $reason): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$sanitizerClass}::mirrors() entry for \${$column} is invalid: {$reason}."
        );
    }

    public static function mirroredColumnNotKeyed(string $modelClass, string $column, string $sanitizerClass): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} is opted in through {$sanitizerClass}::mirrors() but its definition is not a Keyed value. Only Keyed::using() or Keyed::pattern() guarantees that a column and its mirrors receive the same replacement."
        );
    }

    public static function mirrorNotInRun(string $sanitizerClass, string $column, string $mirror): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$sanitizerClass}::mirrors() declares \"{$mirror}\" as a mirror of \${$column}, but no sanitize target in this run declares that column in fields(). Declare it in its table's sanitizer with the same Keyed namespace, and do not exclude that target (for example with --model, which also skips pii.tables)."
        );
    }

    public static function mirrorAmbiguous(string $mirror): self
    {
        return new self(
            "[laravel-pii-sanitizer] Mirror column \"{$mirror}\" is declared in fields() by more than one sanitize target in this run. A mirror column must be sanitized by exactly one target."
        );
    }

    public static function mirrorNamespaceMismatch(string $a, string $b, string $namespaceA, string $namespaceB): self
    {
        return new self(
            "[laravel-pii-sanitizer] \"{$a}\" and \"{$b}\" are declared as mirrors but use different Keyed namespaces (\"{$namespaceA}\" and \"{$namespaceB}\"). Mirrors must share one namespace so the same value maps to the same replacement."
        );
    }

    public static function jsonPathsUnsupportedColumn(string $modelClass, string $column, string $sanitizerClass, string $table, string $reason): self
    {
        return new self(
            "[laravel-pii-sanitizer] {$modelClass}::\${$column} on table \"{$table}\" cannot take a Json::paths() definition in {$sanitizerClass}::fields(): {$reason}."
        );
    }

    public static function jsonPathStaticValueNotWritable(string $modelClass, string $column, string $path, string $sanitizerClass, string $type): self
    {
        return new self(
            "[laravel-pii-sanitizer] The static value for path \"{$path}\" of {$modelClass}::\${$column} in {$sanitizerClass}::fields() is a {$type}, which cannot be written into JSON. Use null, a scalar, an array or an enum."
        );
    }
}
