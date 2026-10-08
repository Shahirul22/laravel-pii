<?php

namespace Shahirul22\LaravelPiiSanitizer;

/**
 * The boot-resolved paging identity for one sanitize target: an ordered,
 * non-empty column list proven unique and NOT NULL, and disjoint from the
 * sanitizer's declared fields() — see docs/design/engine-hardening/spec §R7
 * Paging identity. $source records which precedence tier produced it:
 * 'primary' (schema primary key), 'model-key' (the model's own declared key
 * name, when present in the table's columns, NOT NULL and proven unique at
 * boot), 'unique' (a NOT NULL unique index disjoint from fields(), proven
 * unique at boot), 'declared' (Sanitizer::pagingKey()), or 'fallback' (every
 * NOT NULL string, integer, boolean or datetime column not in fields(),
 * proven unique at boot).
 */
final readonly class PagingKey
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public array $columns,
        public string $source,
    ) {
        if ($columns === []) {
            throw new \InvalidArgumentException('[laravel-pii-sanitizer] A PagingKey must have at least one column.');
        }
    }

    public function isSingle(): bool
    {
        return count($this->columns) === 1;
    }
}
