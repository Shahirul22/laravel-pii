<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shahirul22\LaravelPiiSanitizer\KeysetPredicate;
use Shahirul22\LaravelPiiSanitizer\TableRow;

it('uses a row-value comparison only where it is an index range (BUG-20)', function (string $driver, string $version, bool $expected) {
    expect(KeysetPredicate::supportsRowValues($driver, $version))->toBe($expected);
})->with([
    'PostgreSQL' => ['pgsql', '17.2', true],
    'SQLite 3.45' => ['sqlite', '3.45.1', true],
    'SQLite 3.15' => ['sqlite', '3.15.0', true],
    'SQLite 3.14' => ['sqlite', '3.14.2', false],
    // MySQL 8.4 plans (a, b) > (?, ?) as a full index scan plus a filter,
    // and the OR chain as an index range scan, so MySQL keeps the OR chain.
    'MySQL' => ['mysql', '8.4.0', false],
    'MariaDB' => ['mariadb', '11.4.0', false],
    'other' => ['sqlsrv', '16.0', false],
]);

it('builds a row-value page filter for a composite identity (BUG-20)', function () {
    $query = TableRow::forTable('kp_rows')->newQueryWithoutScopes();

    KeysetPredicate::apply($query, ['org_id', 'member_id'], ['org_id' => 2, 'member_id' => 7], true);

    expect($query->toSql())->toBe('select * from "kp_rows" where ("org_id", "member_id") > (?, ?)');
    expect($query->getBindings())->toBe([2, 7]);
});

it('keeps the expanded OR chain where row values are not used (BUG-20)', function () {
    $query = TableRow::forTable('kp_rows')->newQueryWithoutScopes();

    KeysetPredicate::apply($query, ['org_id', 'member_id'], ['org_id' => 2, 'member_id' => 7], false);

    expect($query->toSql())->toBe('select * from "kp_rows" where (("org_id" > ?) or ("org_id" = ? and "member_id" > ?))');
    expect($query->getBindings())->toBe([2, 2, 7]);
});

it('keeps the plain comparison for a single-column identity (BUG-20)', function () {
    $query = TableRow::forTable('kp_rows')->newQueryWithoutScopes();

    KeysetPredicate::apply($query, ['code'], ['code' => 'b'], true);

    expect($query->toSql())->toBe('select * from "kp_rows" where (("code" > ?))');
});

it('pages a composite identity on SQLite with the row-value filter and visits every row once (BUG-20)', function () {
    Schema::create('kp_rows', function ($table) {
        $table->unsignedBigInteger('org_id');
        $table->unsignedBigInteger('member_id');
        $table->primary(['org_id', 'member_id']);
    });

    foreach ([1, 2, 3] as $org) {
        foreach ([1, 2, 3] as $member) {
            DB::table('kp_rows')->insert(['org_id' => $org, 'member_id' => $member]);
        }
    }

    $query = TableRow::forTable('kp_rows')->newQueryWithoutScopes()->orderBy('org_id')->orderBy('member_id');
    $rowValues = KeysetPredicate::supportsRowValues('sqlite', (string) DB::connection()->getServerVersion());
    expect($rowValues)->toBeTrue();

    $seen = [];
    $lastSeen = null;

    do {
        $page = clone $query;

        if ($lastSeen !== null) {
            KeysetPredicate::apply($page, ['org_id', 'member_id'], $lastSeen, $rowValues);
        }

        $rows = $page->limit(4)->get();

        foreach ($rows as $row) {
            $seen[] = $row->getAttributes()['org_id'].'-'.$row->getAttributes()['member_id'];
        }

        $last = $rows->last()?->getAttributes();
        $lastSeen = $last === null ? null : ['org_id' => $last['org_id'], 'member_id' => $last['member_id']];
    } while ($rows->count() === 4);

    expect($seen)->toBe(['1-1', '1-2', '1-3', '2-1', '2-2', '2-3', '3-1', '3-2', '3-3']);
});
