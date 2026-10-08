<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;
use Shahirul22\LaravelPiiSanitizer\ConstraintValidator;
use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;

// --- Pure parsing tests: parse() against hand-written getColumns()-shaped fixtures ---

function ccMysqlColumn(string $name, string $type, string $typeName, bool $nullable = true): array
{
    return ['name' => $name, 'type_name' => $typeName, 'type' => $type, 'nullable' => $nullable];
}

it('parses MySQL/MariaDB column types', function (string $driver) {
    $inspector = app(ColumnConstraintInspector::class);

    $columns = [
        ccMysqlColumn('code', 'varchar(255)', 'varchar'),
        ccMysqlColumn('short', 'char(2)', 'char'),
        ccMysqlColumn('status', "enum('a','b','it''s')", 'enum'),
        ccMysqlColumn('flags', "set('x','y')", 'set'),
        ccMysqlColumn('flag', 'tinyint(1)', 'tinyint'),
        ccMysqlColumn('age', 'int(11)', 'int'),
        ccMysqlColumn('big', 'bigint unsigned', 'bigint'),
        ccMysqlColumn('price', 'decimal(8,2)', 'decimal'),
        ccMysqlColumn('at', 'datetime', 'datetime'),
        ccMysqlColumn('meta', 'json', 'json'),
        ccMysqlColumn('blob', 'blob', 'blob'),
        ccMysqlColumn('required', 'varchar(10)', 'varchar', false),
    ];

    $result = $inspector->parse($driver, $columns, null);

    expect($result['code']->family)->toBe('string');
    expect($result['code']->maxLength)->toBe(255);
    expect($result['short']->maxLength)->toBe(2);
    expect($result['status']->family)->toBe('enum');
    expect($result['status']->allowed)->toBe(['a', 'b', "it's"]);
    expect($result['flags']->family)->toBe('set');
    expect($result['flags']->allowed)->toBe(['x', 'y']);
    expect($result['flag']->family)->toBe('boolean');
    expect($result['age']->family)->toBe('integer');
    expect($result['big']->family)->toBe('integer');
    expect($result['price']->family)->toBe('decimal');
    expect($result['at']->family)->toBe('datetime');
    expect($result['meta']->family)->toBe('json');
    expect($result['blob']->family)->toBe('binary');
    expect($result['required']->nullable)->toBeFalse();
})->with(['mysql', 'mariadb']);

it('parses PostgreSQL column types', function () {
    $inspector = app(ColumnConstraintInspector::class);

    $columns = [
        ['name' => 'code', 'type_name' => 'varchar', 'type' => 'character varying(100)', 'nullable' => true],
        ['name' => 'short', 'type_name' => 'bpchar', 'type' => 'character(3)', 'nullable' => true],
        ['name' => 'note', 'type_name' => 'text', 'type' => 'text', 'nullable' => true],
        ['name' => 'age', 'type_name' => 'int4', 'type' => 'integer', 'nullable' => true],
        ['name' => 'big', 'type_name' => 'int8', 'type' => 'bigint', 'nullable' => true],
        ['name' => 'flag', 'type_name' => 'bool', 'type' => 'boolean', 'nullable' => true],
        ['name' => 'price', 'type_name' => 'float8', 'type' => 'double precision', 'nullable' => true],
        ['name' => 'amount', 'type_name' => 'numeric', 'type' => 'numeric', 'nullable' => true],
        ['name' => 'at', 'type_name' => 'timestamp', 'type' => 'timestamp', 'nullable' => true],
        ['name' => 'atz', 'type_name' => 'timestamptz', 'type' => 'timestamptz', 'nullable' => true],
        ['name' => 'meta', 'type_name' => 'jsonb', 'type' => 'jsonb', 'nullable' => true],
        ['name' => 'blob', 'type_name' => 'bytea', 'type' => 'bytea', 'nullable' => true],
    ];

    $result = $inspector->parse('pgsql', $columns, null);

    expect($result['code']->maxLength)->toBe(100);
    expect($result['short']->maxLength)->toBe(3);
    expect($result['note']->family)->toBe('string');
    expect($result['note']->maxLength)->toBeNull();
    expect($result['age']->family)->toBe('integer');
    expect($result['big']->family)->toBe('integer');
    expect($result['flag']->family)->toBe('boolean');
    expect($result['price']->family)->toBe('decimal');
    expect($result['amount']->family)->toBe('decimal');
    expect($result['at']->family)->toBe('datetime');
    expect($result['atz']->family)->toBe('datetime');
    expect($result['meta']->family)->toBe('json');
    expect($result['blob']->family)->toBe('binary');

    foreach ($result as $constraints) {
        expect($constraints->allowed)->toBeNull();
    }
});

it('parses SQLite column types, reading the allowed set from DDL and never enforcing length', function () {
    $inspector = app(ColumnConstraintInspector::class);

    $columns = [
        ['name' => 'status', 'type_name' => 'varchar', 'type' => 'varchar', 'nullable' => false],
        ['name' => 'name', 'type_name' => 'varchar', 'type' => 'varchar', 'nullable' => false],
        ['name' => 'flag', 'type_name' => 'tinyint', 'type' => 'tinyint(1)', 'nullable' => true],
        ['name' => 'age', 'type_name' => 'integer', 'type' => 'integer', 'nullable' => true],
        ['name' => 'amount', 'type_name' => 'numeric', 'type' => 'numeric', 'nullable' => true],
        ['name' => 'note', 'type_name' => 'text', 'type' => 'text', 'nullable' => true],
    ];

    $ddl = <<<'SQL'
        CREATE TABLE "t" ("id" integer primary key autoincrement not null, "status" varchar check ("status" in ('active', 'o''brien')) not null, "name" varchar not null)
        SQL;

    $result = $inspector->parse('sqlite', $columns, $ddl);

    expect($result['status']->allowed)->toBe(['active', "o'brien"]);
    expect($result['name']->allowed)->toBeNull();
    expect($result['status']->maxLength)->toBeNull();
    expect($result['flag']->family)->toBe('boolean');
    expect($result['age']->family)->toBe('integer');
    expect($result['amount']->family)->toBe('decimal');
    expect($result['note']->family)->toBe('string');
});

it('treats an unknown driver as unvalidated except for nullability', function () {
    $inspector = app(ColumnConstraintInspector::class);

    $columns = [
        ['name' => 'code', 'type_name' => 'varchar', 'type' => 'varchar(255)', 'nullable' => false],
        ['name' => 'flag', 'type_name' => 'bit', 'type' => 'bit', 'nullable' => true],
    ];

    $result = $inspector->parse('sqlsrv', $columns, null);

    expect($result['code']->family)->toBe('other');
    expect($result['code']->maxLength)->toBeNull();
    expect($result['code']->allowed)->toBeNull();
    expect($result['code']->nullable)->toBeFalse();
    expect($result['flag']->nullable)->toBeTrue();
});

// --- End-to-end tests over the Testbench SQLite connection ---

beforeEach(function () {
    Schema::create('cci_rows', function ($table) {
        $table->id();
        $table->enum('status', ['a', 'b']);
        $table->string('name');
        $table->string('nick')->nullable();
        $table->boolean('flag');
        $table->integer('age');
    });
});

afterEach(function () {
    DB::connection()->setTablePrefix('');
});

it('builds a column => ColumnConstraints map for a real SQLite table', function () {
    $inspector = app(ColumnConstraintInspector::class);

    $map = $inspector->constraintsFor('cci_rows');

    expect($map['status']->allowed)->toBe(['a', 'b']);
    expect($map['name']->nullable)->toBeFalse();
    expect($map['nick']->nullable)->toBeTrue();
    expect($map['flag']->family)->toBe('boolean');
    expect($map['age']->family)->toBe('integer');
});

it('memoizes introspection per (connection, table), issuing zero queries on repeat', function () {
    $inspector = app(ColumnConstraintInspector::class);

    $inspector->constraintsFor('cci_rows');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $inspector->constraintsFor('cci_rows');

    expect(DB::getQueryLog())->toBe([]);
});

it('finds the allowed set of an enum column under a configured table prefix', function () {
    DB::connection()->setTablePrefix('wp_');

    Schema::create('cci_prefixed_rows', function ($table) {
        $table->id();
        $table->enum('status', ['active', 'inactive']);
    });

    $inspector = app(ColumnConstraintInspector::class);

    $map = $inspector->constraintsFor('cci_prefixed_rows');

    expect($map['status']->allowed)->toBe(['active', 'inactive']);
});

it('keeps each column\'s native type string as the schema reports it (BUG-34)', function () {
    $inspector = app(ColumnConstraintInspector::class);

    $result = $inspector->parse('pgsql', [
        ['name' => 'code', 'type_name' => 'varchar', 'type' => 'character varying(100)', 'nullable' => true],
        ['name' => 'mood', 'type_name' => 'Mood', 'type' => '"Mood"', 'nullable' => true],
    ]);

    expect($result['code']->nativeType)->toBe('character varying(100)');
    expect($result['mood']->nativeType)->toBe('"Mood"');
    expect($inspector->parse('sqlsrv', [['name' => 'x', 'type_name' => 'int', 'type' => 'int', 'nullable' => true]])['x']->nativeType)->toBe('int');
});

it('keeps the case of MySQL/MariaDB enum and set members (BUG-2)', function (string $driver) {
    $inspector = app(ColumnConstraintInspector::class);

    $result = $inspector->parse($driver, [
        ccMysqlColumn('role', "ENUM('Admin','User')", 'enum'),
        ccMysqlColumn('flags', "set('A','B')", 'set'),
    ]);

    expect($result['role']->family)->toBe('enum');
    expect($result['role']->allowed)->toBe(['Admin', 'User']);
    expect($result['flags']->family)->toBe('set');
    expect($result['flags']->allowed)->toBe(['A', 'B']);
})->with(['mysql', 'mariadb']);

it('accepts a correct-case enum or set member once parsed (BUG-2)', function () {
    $inspector = app(ColumnConstraintInspector::class);
    $validator = new ConstraintValidator;

    $result = $inspector->parse('mysql', [
        ccMysqlColumn('role', "enum('Admin','User')", 'enum'),
        ccMysqlColumn('flags', "set('A','B')", 'set'),
    ]);

    $validator->assertWritable('App\\Models\\User', 'users', $result['role'], 'Admin');
    $validator->assertWritable('App\\Models\\User', 'users', $result['flags'], 'A,B');

    expect(fn () => $validator->assertWritable('App\\Models\\User', 'users', $result['role'], 'admin'))
        ->toThrow(ConstraintViolationException::class);
});

it('accepts the full tinyint range on a MySQL tinyint(1) column, which may hold small codes as well as booleans (BUG-41)', function (string $driver) {
    $inspector = app(ColumnConstraintInspector::class);
    $validator = new ConstraintValidator;

    $flag = $inspector->parse($driver, [ccMysqlColumn('gender', 'tinyint(1)', 'tinyint')])['gender'];

    foreach ([2, -128, 127, '2', '-5', true, false, 0, 1] as $good) {
        $validator->assertWritable('App\\Models\\User', 'users', $flag, $good);
    }

    foreach ([128, -129, '128', 'yes', 1.5] as $bad) {
        expect(fn () => $validator->assertWritable('App\\Models\\User', 'users', $flag, $bad))
            ->toThrow(ConstraintViolationException::class);
    }
})->with(['mysql', 'mariadb']);

it('keeps a real boolean column to booleans and 0/1 (BUG-41)', function () {
    $inspector = app(ColumnConstraintInspector::class);
    $validator = new ConstraintValidator;

    $flag = $inspector->parse('pgsql', [['name' => 'flag', 'type_name' => 'bool', 'type' => 'boolean', 'nullable' => true]])['flag'];

    expect(fn () => $validator->assertWritable('App\\Models\\User', 'users', $flag, 2))
        ->toThrow(ConstraintViolationException::class);
});

it('gives a PostgreSQL array of a sized string type no length limit, leaving each element to the database (BUG-26)', function () {
    $inspector = app(ColumnConstraintInspector::class);
    $validator = new ConstraintValidator;

    $result = $inspector->parse('pgsql', [
        ['name' => 'va', 'type_name' => '_varchar', 'type' => 'character varying(5)[]', 'nullable' => true],
        ['name' => 'ca', 'type_name' => '_bpchar', 'type' => 'character(3)[]', 'nullable' => true],
        ['name' => 'vm', 'type_name' => '_varchar', 'type' => 'character varying(5)[][]', 'nullable' => true],
        ['name' => 'code', 'type_name' => 'varchar', 'type' => 'character varying(5)', 'nullable' => true],
        ['name' => 'short', 'type_name' => 'bpchar', 'type' => 'character(3)', 'nullable' => true],
    ]);

    expect($result['va']->maxLength)->toBeNull();
    expect($result['ca']->maxLength)->toBeNull();
    expect($result['vm']->maxLength)->toBeNull();
    expect($result['code']->maxLength)->toBe(5);
    expect($result['short']->maxLength)->toBe(3);

    $validator->assertWritable('App\\Models\\User', 'users', $result['va'], '{abc,def}');
});

it('gives a MySQL/MariaDB enum column its own family, still checked as a scalar in its allowed set (BUG-20)', function (string $driver) {
    $inspector = app(ColumnConstraintInspector::class);
    $validator = new ConstraintValidator;

    $role = $inspector->parse($driver, [ccMysqlColumn('role', "enum('viewer','admin')", 'enum', false)])['role'];

    expect($role->family)->toBe('enum');

    $validator->assertWritable('App\\Models\\User', 'users', $role, 'admin');

    foreach (['editor', new DateTimeImmutable('2024-01-01'), null] as $bad) {
        expect(fn () => $validator->assertWritable('App\\Models\\User', 'users', $role, $bad))
            ->toThrow(ConstraintViolationException::class);
    }
})->with(['mysql', 'mariadb']);
