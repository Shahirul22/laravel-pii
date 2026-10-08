<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shahirul22\LaravelPiiSanitizer\BatchUpdateStatement;
use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;

function aucRows(): array
{
    return [
        ['identity' => ['id' => 1], 'values' => ['email' => 'a@x.test']],
        ['identity' => ['id' => 2], 'values' => ['email' => 'b@x.test']],
    ];
}

it('assigns an ON UPDATE column to itself so MySQL does not set it to now, on the single-key and the composite-key statement (BUG-25)', function () {
    $mysql = new MySqlConnection(fn () => throw new LogicException('no PDO in this test'), 'pii');

    [$single] = BatchUpdateStatement::build($mysql->getQueryGrammar(), 'users', ['id'], ['email'], aucRows(), [], ['updated_at']);

    expect($single)->toBe('UPDATE `users` SET `email` = CASE `id` WHEN ? THEN ? WHEN ? THEN ? END, `updated_at` = `updated_at` WHERE `id` IN (?, ?)');

    $rows = [['identity' => ['org_id' => 1, 'member_id' => 5], 'values' => ['email' => 'a@x.test']]];

    [$composite, $bindings] = BatchUpdateStatement::build($mysql->getQueryGrammar(), 'members', ['org_id', 'member_id'], ['email'], $rows, [], ['updated_at']);

    expect($composite)->toBe('UPDATE `members` SET `email` = CASE WHEN `org_id` = ? AND `member_id` = ? THEN ? END, `updated_at` = `updated_at` WHERE (`org_id` = ? AND `member_id` = ?)');
    expect($bindings)->toBe([1, 5, 'a@x.test', 1, 5]);
});

it('writes an ON UPDATE column declared in fields() with its replacement, not with itself (BUG-25)', function () {
    $mysql = new MySqlConnection(fn () => throw new LogicException('no PDO in this test'), 'pii');

    $rows = [['identity' => ['id' => 1], 'values' => ['email' => 'a@x.test', 'updated_at' => '2020-01-01 00:00:00']]];

    [$sql] = BatchUpdateStatement::build($mysql->getQueryGrammar(), 'users', ['id'], ['email', 'updated_at'], $rows, [], ['updated_at']);

    expect($sql)->toBe('UPDATE `users` SET `email` = CASE `id` WHEN ? THEN ? END, `updated_at` = CASE `id` WHEN ? THEN ? END WHERE `id` IN (?)');
});

it('leaves the statement byte for byte unchanged when no column updates itself (BUG-25)', function () {
    $pgsql = new PostgresConnection(fn () => throw new LogicException('no PDO in this test'), 'pii');

    [$without] = BatchUpdateStatement::build($pgsql->getQueryGrammar(), 'users', ['id'], ['email'], aucRows());
    [$withEmpty] = BatchUpdateStatement::build($pgsql->getQueryGrammar(), 'users', ['id'], ['email'], aucRows(), [], []);

    expect($withEmpty)->toBe($without);
    expect($without)->toBe('UPDATE "users" SET "email" = CASE "id" WHEN ? THEN ? WHEN ? THEN ? END WHERE "id" IN (?, ?)');
});

it('reads the ON UPDATE columns from the MySQL and MariaDB EXTRA attribute (BUG-25)', function () {
    $rows = [
        (object) ['name' => 'id', 'extra' => 'auto_increment'],
        (object) ['name' => 'email', 'extra' => ''],
        (object) ['name' => 'note', 'extra' => null],
        (object) ['name' => 'created_at', 'extra' => 'DEFAULT_GENERATED'],
        (object) ['name' => 'updated_at', 'extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'],
        ['name' => 'touched_at', 'extra' => 'on update current_timestamp()'],
        (object) ['name' => 'precise_at', 'extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP(6)'],
    ];

    expect(ColumnConstraintInspector::autoUpdatedColumnsFrom($rows))->toBe(['updated_at', 'touched_at', 'precise_at']);
});

it('finds no ON UPDATE columns and runs no query on a driver other than MySQL or MariaDB (BUG-25)', function () {
    Schema::create('auc_rows', function ($table) {
        $table->id();
        $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    });

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(app(ColumnConstraintInspector::class)->autoUpdatedColumns(DB::connection(), 'auc_rows'))->toBe([]);
    expect(DB::getQueryLog())->toBe([]);
});
