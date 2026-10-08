<?php

namespace {
    use Illuminate\Database\PostgresConnection;
    use Illuminate\Database\Schema\Builder;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\ForeignKeyInspector;

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     * @return array{name: string, columns: list<string>, foreign_schema: string, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}
     */
    function fktnForeignKey(array $columns, string $foreignSchema, string $foreignTable, array $foreignColumns): array
    {
        return [
            'name' => 'fk_'.implode('_', $columns),
            'columns' => $columns,
            'foreign_schema' => $foreignSchema,
            'foreign_table' => $foreignTable,
            'foreign_columns' => $foreignColumns,
            'on_update' => 'no action',
            'on_delete' => 'no action',
        ];
    }

    /**
     * A PostgreSQL connection whose schema builder lists schema-qualified
     * names, the way Laravel 12 does for every schema in the database. The
     * builder answers getForeignKeys() only for the name the database
     * itself would resolve, and records every name it was asked for.
     *
     * @param  array<string, list<array<string, mixed>>>  $foreignKeys
     * @param  list<string>  $queried
     */
    function fktnPostgresConnection(array $foreignKeys, array &$queried): PostgresConnection
    {
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('getTableListing')->andReturn(['hr.chi2', 'hr.dept', 'hr.emp2', 'public.emp2', 'public.par2']);
        $builder->shouldReceive('getForeignKeys')->andReturnUsing(function (string $table) use ($foreignKeys, &$queried) {
            $queried[] = $table;

            return $foreignKeys[$table] ?? [];
        });

        $prefix = '';

        $connection = Mockery::mock(PostgresConnection::class);
        $connection->shouldReceive('getName')->andReturn('pg_stub');
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('getSchemaBuilder')->andReturn($builder);
        $connection->shouldReceive('scalar')->with('select current_schema()')->andReturn('public');
        $connection->shouldReceive('getTablePrefix')->andReturnUsing(function () use (&$prefix) {
            return $prefix;
        });
        $connection->shouldReceive('setTablePrefix')->andReturnUsing(function (string $value) use (&$prefix, &$connection) {
            $prefix = $value;

            return $connection;
        });

        return $connection;
    }

    afterEach(function () {
        DB::connection()->setTablePrefix('');
    });

    it('keeps the schema of a schema-qualified table through the foreign-key sweep (BUG-42)', function () {
        $queried = [];

        $connection = fktnPostgresConnection([
            // getForeignKeys('hr.emp2') is the only name that finds hr.emp2's key; 'emp2' is public.emp2, which has none.
            'hr.emp2' => [fktnForeignKey(['dept_ref'], 'hr', 'dept', ['id'])],
            'hr.chi2' => [fktnForeignKey(['nric'], 'public', 'par2', ['nric'])],
        ], $queried);

        $inspector = new ForeignKeyInspector;

        expect($inspector->outboundForeignKeyColumns($connection, 'hr.emp2'))->toBe(['dept_ref']);
        expect($inspector->outboundTarget($connection, 'hr.emp2', 'dept_ref'))->toBe('hr.dept');
        expect($inspector->outboundForeignKeyColumns($connection, 'emp2'))->toBe([]);

        expect($inspector->inboundReferencedColumns($connection, 'hr.dept'))->toBe(['id' => 'hr.emp2']);
        expect($inspector->inboundReferencedColumns($connection, 'dept'))->toBe([]);
        expect($inspector->inboundReferencedColumns($connection, 'par2'))->toBe(['nric' => 'hr.chi2']);
        expect($inspector->inboundReferencedColumns($connection, 'public.par2'))->toBe(['nric' => 'hr.chi2']);

        $edges = $inspector->edgesTouching($connection, 'par2', 'nric');
        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_table'])->toBe('hr.chi2');
        expect($edges[0]['parent_table'])->toBe('par2');

        expect($queried)->toContain('hr.emp2', 'hr.chi2', 'hr.dept');
        expect($queried)->not->toContain('chi2');
    });

    it('reads the foreign keys of a listed table that lacks the connection prefix (BUG-25)', function () {
        DB::connection()->setTablePrefix('wp_');

        Schema::create('b25_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('code')->unique();
        });

        // A prefixed referencing table, created through the schema builder ...
        Schema::create('b25_orders', function ($table) {
            $table->id();
            $table->string('user_code');
            $table->foreign('user_code')->references('code')->on('b25_users');
        });

        // ... and one in the same database without the prefix.
        DB::statement('create table "b25_legacy_audit" ("id" integer primary key, "user_email" varchar references "wp_b25_users" ("email"))');

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns(DB::connection(), 'b25_users'))->toBe([
            'email' => 'b25_legacy_audit',
            'code' => 'b25_orders',
        ]);

        $edges = $inspector->edgesTouching(DB::connection(), 'b25_users', 'email');
        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_table'])->toBe('b25_legacy_audit');
        expect($edges[0]['child_columns'])->toBe(['user_email']);
    });

    it('resolves an implicit SQLite foreign key to the primary key of the referenced table (BUG-17)', function () {
        DB::statement('create table "b17_customers" ("nric" varchar primary key, "name" varchar)');
        DB::statement('create table "b17_orders" ("id" integer primary key, "cust" varchar references "b17_customers")');

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns(DB::connection(), 'b17_customers'))->toBe(['nric' => 'b17_orders']);

        $edges = $inspector->edgesTouching(DB::connection(), 'b17_customers', 'nric');
        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_columns'])->toBe(['cust']);
        expect($edges[0]['parent_columns'])->toBe(['nric']);
    });

    it('resolves an implicit composite SQLite foreign key to the composite primary key (BUG-17)', function () {
        DB::statement('create table "b17_parents" ("a" varchar, "b" varchar, primary key ("a", "b"))');
        DB::statement('create table "b17_children" ("id" integer primary key, "p" varchar, "q" varchar, foreign key ("p", "q") references "b17_parents")');

        $edges = (new ForeignKeyInspector)->edgesTouching(DB::connection(), 'b17_parents', 'b');

        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_columns'])->toBe(['p', 'q']);
        expect($edges[0]['parent_columns'])->toBe(['a', 'b']);
    });

    it('resolves an implicit foreign key under a table prefix (BUG-17)', function () {
        DB::connection()->setTablePrefix('wp_');

        DB::statement('create table "wp_b17_customers" ("nric" varchar primary key)');
        DB::statement('create table "wp_b17_orders" ("id" integer primary key, "cust" varchar references "wp_b17_customers")');

        expect((new ForeignKeyInspector)->inboundReferencedColumns(DB::connection(), 'b17_customers'))->toBe(['nric' => 'b17_orders']);
    });

    it('names the table and column of an implicit foreign key it cannot resolve (BUG-17)', function () {
        DB::statement('create table "b17_keyless" ("x" varchar)');
        DB::statement('create table "b17_dangling" ("id" integer primary key, "y" varchar references "b17_keyless")');

        expect(fn () => (new ForeignKeyInspector)->edgesTouching(DB::connection(), 'b17_keyless', 'x'))
            ->toThrow(UnsafeColumnException::class, UnsafeColumnException::unresolvedForeignKey('b17_dangling', ['y'], 'b17_keyless')->getMessage());
    });
}
