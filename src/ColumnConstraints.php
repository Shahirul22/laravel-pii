<?php

namespace Shahirul22\LaravelPiiSanitizer;

/**
 * Schema-sourced constraints for one column, used by ConstraintValidator to
 * validate a generated replacement value before it is written — see
 * docs/design/engine-hardening/spec §R6.
 *
 * $family is one of: integer, decimal, boolean, datetime, json, string, set,
 * binary, other. `set` is the MySQL SET family specifically: type-checked as
 * a string, but its allowed-set membership is checked per comma-separated
 * member rather than against the whole value, so a valid multi-member SET
 * value (e.g. "a,c") is not falsely rejected as not matching any single
 * allowed entry.
 */
final readonly class ColumnConstraints
{
    /**
     * @param  list<string>|null  $allowed
     */
    public function __construct(
        public string $column,
        public string $family,
        public ?int $maxLength,
        public ?array $allowed,
        public bool $nullable,
    ) {}
}
