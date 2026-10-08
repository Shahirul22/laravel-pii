<?php

use Shahirul22\LaravelPiiSanitizer\BatchUpdateStatement;

/*
 * Every format_type() string family the PostgreSQL value cast touches, and
 * the cast fragment it must produce. Each row was checked against a real
 * PostgreSQL 17 server: a value that is valid for the column is stored
 * exactly, and an over-long value for a character(n), character varying(n)
 * or bit(n) column still fails with the column's own length error instead
 * of being cut short by the cast.
 */
it('maps a PostgreSQL column type to a cast that never truncates the value (BUG-1)', function (string $type, string $expected) {
    expect(BatchUpdateStatement::postgresCastType($type))->toBe($expected);
})->with([
    // character(n): a bare `character` is character(1) and would cut the
    // value to one character, so the unbounded bpchar is used.
    'character(10)' => ['character(10)', 'bpchar'],
    'character(1)' => ['character(1)', 'bpchar'],
    'bpchar' => ['bpchar', 'bpchar'],
    'character(3)[]' => ['character(3)[]', 'bpchar[]'],
    // bit(n): a bare `bit` is bit(1), so the unbounded bit varying is used.
    'bit(5)' => ['bit(5)', 'bit varying'],
    'bit(1)' => ['bit(1)', 'bit varying'],
    'bit(3)[]' => ['bit(3)[]', 'bit varying[]'],
    'bit varying(8)' => ['bit varying(8)', 'bit varying'],
    'bit varying' => ['bit varying', 'bit varying'],
    'character varying(255)' => ['character varying(255)', 'character varying'],
    'character varying' => ['character varying', 'character varying'],
    'character varying(5)[]' => ['character varying(5)[]', 'character varying[]'],
    'text' => ['text', 'text'],
    'text[]' => ['text[]', 'text[]'],
    'numeric(8,2)' => ['numeric(8,2)', 'numeric'],
    'numeric' => ['numeric', 'numeric'],
    'smallint' => ['smallint', 'smallint'],
    'integer' => ['integer', 'integer'],
    'bigint' => ['bigint', 'bigint'],
    'integer[]' => ['integer[]', 'integer[]'],
    'real' => ['real', 'real'],
    'double precision' => ['double precision', 'double precision'],
    'boolean' => ['boolean', 'boolean'],
    'date' => ['date', 'date'],
    'time(3) without time zone' => ['time(3) without time zone', 'time without time zone'],
    'time with time zone' => ['time with time zone', 'time with time zone'],
    'timestamp(0) without time zone' => ['timestamp(0) without time zone', 'timestamp without time zone'],
    'timestamp with time zone' => ['timestamp with time zone', 'timestamp with time zone'],
    'interval' => ['interval', 'interval'],
    'interval(3)' => ['interval(3)', 'interval'],
    'interval year to month' => ['interval year to month', 'interval year to month'],
    'interval day to second(3)' => ['interval day to second(3)', 'interval day to second'],
    'uuid' => ['uuid', 'uuid'],
    'json' => ['json', 'json'],
    'jsonb' => ['jsonb', 'jsonb'],
    'bytea' => ['bytea', 'bytea'],
    'inet' => ['inet', 'inet'],
    'cidr' => ['cidr', 'cidr'],
    'macaddr' => ['macaddr', 'macaddr'],
    'money' => ['money', 'money'],
    'citext' => ['citext', 'citext'],
    'int4range' => ['int4range', 'int4range'],
    'tstzrange' => ['tstzrange', 'tstzrange'],
    'an enum type' => ['mood', 'mood'],
    'a quoted enum type' => ['"Mood"', '"Mood"'],
    'a schema-qualified type' => ['public.mood', 'public.mood'],
    'a domain' => ['postal_code', 'postal_code'],
    'the internal "char" type' => ['"char"', '"char"'],
    'a user type named like character' => ['character_set', 'character_set'],
    'a user type named like bit' => ['bitmask', 'bitmask'],
]);
