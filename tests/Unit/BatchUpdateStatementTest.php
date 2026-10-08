<?php

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Shahirul22\LaravelPiiSanitizer\BatchUpdateStatement;
use Shahirul22\LaravelPiiSanitizer\ColumnConstraints;

function busPgsql(): Connection
{
    // Never connects: building SQL only needs the grammar.
    return new PostgresConnection(fn () => throw new LogicException('no PDO in this test'), 'pii');
}

function busRows(): array
{
    return [
        ['identity' => ['id' => 1], 'values' => ['age' => 42, 'name' => 'a']],
        ['identity' => ['id' => 2], 'values' => ['age' => 43, 'name' => 'b']],
    ];
}

it('builds the unchanged v1 single-identity statement when no value type is given', function () {
    [$sql, $bindings] = BatchUpdateStatement::build(DB::connection()->getQueryGrammar(), 'users', ['id'], ['age', 'name'], busRows());

    expect($sql)->toBe('UPDATE "users" SET "age" = CASE "id" WHEN ? THEN ? WHEN ? THEN ? END, "name" = CASE "id" WHEN ? THEN ? WHEN ? THEN ? END WHERE "id" IN (?, ?)');
    expect($bindings)->toBe([1, 42, 2, 43, 1, 'a', 2, 'b', 1, 2]);
});

it('builds the unchanged composite-identity statement when no value type is given', function () {
    $rows = [
        ['identity' => ['org_id' => 1, 'member_id' => 5], 'values' => ['age' => 42]],
        ['identity' => ['org_id' => 2, 'member_id' => 6], 'values' => ['age' => 43]],
    ];

    [$sql, $bindings] = BatchUpdateStatement::build(DB::connection()->getQueryGrammar(), 'members', ['org_id', 'member_id'], ['age'], $rows);

    expect($sql)->toBe('UPDATE "members" SET "age" = CASE WHEN "org_id" = ? AND "member_id" = ? THEN ? WHEN "org_id" = ? AND "member_id" = ? THEN ? END WHERE ("org_id" = ? AND "member_id" = ?) OR ("org_id" = ? AND "member_id" = ?)');
    expect($bindings)->toBe([1, 5, 42, 2, 6, 43, 1, 5, 2, 6]);
});

it('casts every bound value to its column type on PostgreSQL (BUG-34)', function () {
    [$sql, $bindings] = BatchUpdateStatement::build(busPgsql()->getQueryGrammar(), 'users', ['id'], ['age', 'name'], busRows(), [
        'age' => 'integer',
        'name' => 'character varying',
    ]);

    expect($sql)->toBe('UPDATE "users" SET "age" = CASE "id" WHEN ? THEN CAST(? AS integer) WHEN ? THEN CAST(? AS integer) END, "name" = CASE "id" WHEN ? THEN CAST(? AS character varying) WHEN ? THEN CAST(? AS character varying) END WHERE "id" IN (?, ?)');
    expect($bindings)->toBe([1, 42, 2, 43, 1, 'a', 2, 'b', 1, 2]);
});

it('casts every bound value on a composite identity too (BUG-34)', function () {
    $rows = [
        ['identity' => ['org_id' => 1, 'member_id' => 5], 'values' => ['born' => '2001-02-03']],
    ];

    [$sql] = BatchUpdateStatement::build(busPgsql()->getQueryGrammar(), 'members', ['org_id', 'member_id'], ['born'], $rows, ['born' => 'date']);

    expect($sql)->toBe('UPDATE "members" SET "born" = CASE WHEN "org_id" = ? AND "member_id" = ? THEN CAST(? AS date) END WHERE ("org_id" = ? AND "member_id" = ?)');
});

it('derives cast types from the schema on PostgreSQL only (BUG-34)', function () {
    $constraints = [
        'age' => new ColumnConstraints('age', 'integer', null, null, false, 'integer'),
        'name' => new ColumnConstraints('name', 'string', 100, null, true, 'character varying(100)'),
        'price' => new ColumnConstraints('price', 'decimal', null, null, true, 'numeric(8,2)'),
        'at' => new ColumnConstraints('at', 'datetime', null, null, true, 'timestamp(0) without time zone'),
        'tags' => new ColumnConstraints('tags', 'other', null, null, true, 'character varying(20)[]'),
        'mood' => new ColumnConstraints('mood', 'other', null, null, true, '"Mood(1)"'),
        'stub' => new ColumnConstraints('stub', 'string', null, null, true),
    ];

    expect(BatchUpdateStatement::valueTypesFor('pgsql', $constraints))->toBe([
        'age' => 'integer',
        'name' => 'character varying',
        'price' => 'numeric',
        'at' => 'timestamp without time zone',
        'tags' => 'character varying[]',
        'mood' => '"Mood(1)"',
    ]);

    foreach (['sqlite', 'mysql', 'mariadb', 'sqlsrv'] as $driver) {
        expect(BatchUpdateStatement::valueTypesFor($driver, $constraints))->toBe([]);
    }
});

it('drops the length or precision so the cast never truncates or rounds before the column does (BUG-34)', function (string $type, string $expected) {
    expect(BatchUpdateStatement::postgresCastType($type))->toBe($expected);
})->with([
    ['character varying(255)', 'character varying'],
    ['character(3)', 'bpchar'],
    ['bit varying(8)', 'bit varying'],
    ['numeric(10, 2)', 'numeric'],
    ['time(6) with time zone', 'time with time zone'],
    ['jsonb', 'jsonb'],
    ['uuid', 'uuid'],
    ['double precision', 'double precision'],
    ['public.mood', 'public.mood'],
]);

it('refuses a type string that is not a plain type name (BUG-34)', function () {
    expect(BatchUpdateStatement::postgresCastType('integer); drop table users; --'))->toBeNull();
    expect(BatchUpdateStatement::postgresCastType(''))->toBeNull();
});

it('passes the MySQL statement through byte for byte (BUG-34)', function () {
    $mysql = new MySqlConnection(fn () => throw new LogicException('no PDO in this test'), 'pii');

    [$sql] = BatchUpdateStatement::build($mysql->getQueryGrammar(), 'users', ['id'], ['age'], busRows(), BatchUpdateStatement::valueTypesFor('mysql', [
        'age' => new ColumnConstraints('age', 'integer', null, null, false, 'int'),
    ]));

    expect($sql)->toBe('UPDATE `users` SET `age` = CASE `id` WHEN ? THEN ? WHEN ? THEN ? END WHERE `id` IN (?, ?)');
});
