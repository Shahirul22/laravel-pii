<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\MySqlConnection;
    use Illuminate\Database\Schema\Builder;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class FkcParent extends Model
    {
        protected $table = 'fkc_parents';

        protected $guarded = [];

        public $timestamps = false;
    }

    class FkcChild extends Model
    {
        protected $table = 'fkc_children';

        protected $guarded = [];

        public $timestamps = false;
    }

    class FkcSanitizer extends Sanitizer
    {
        /**
         * @param  array<string, mixed>  $fields
         * @param  array<string, list<string>>  $mirrors
         */
        public function __construct(private array $fields = [], private array $mirrors = []) {}

        public function fields(): array
        {
            return $this->fields;
        }

        public function mirrors(): array
        {
            return $this->mirrors;
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     * @return array{name: string, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}
     */
    function fkcForeignKey(array $columns, ?string $foreignSchema, string $foreignTable, array $foreignColumns): array
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
     * A schema builder that lists $listing and answers getForeignKeys() for
     * the names in $foreignKeys only.
     *
     * @param  list<string>  $listing
     * @param  array<string, list<array<string, mixed>>>  $foreignKeys
     */
    function fkcBuilder(array $listing, array $foreignKeys): Builder
    {
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('getTableListing')->andReturn($listing);
        $builder->shouldReceive('getForeignKeys')->andReturnUsing(fn (string $table) => $foreignKeys[$table] ?? []);

        return $builder;
    }

    /**
     * A MySQL connection as Laravel 12 sees it: getTableListing() returns
     * database-qualified names ("app.orders").
     *
     * @param  list<string>  $listing
     * @param  array<string, list<array<string, mixed>>>  $foreignKeys
     */
    function fkcMySqlConnection(string $database, array $listing, array $foreignKeys): MySqlConnection
    {
        $connection = Mockery::mock(MySqlConnection::class);
        $connection->shouldReceive('getName')->andReturn('mysql_stub');
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn($database);
        $connection->shouldReceive('getSchemaBuilder')->andReturn(fkcBuilder($listing, $foreignKeys));
        $connection->shouldReceive('getTablePrefix')->andReturn('');

        return $connection;
    }

    function fkcKeyed(): Keyed
    {
        return Keyed::pattern('fkc', '######');
    }
}

namespace {

    use Illuminate\Database\PostgresConnection;
    use Illuminate\Support\Facades\DB;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\ForeignKeyInspector;
    use Shahirul22\LaravelPiiSanitizer\ReferencedColumnGuard;
    use Shahirul22\LaravelPiiSanitizer\SchemaGuard;
    use Shahirul22\LaravelPiiSanitizer\TableRow;

    // BUG-17 (round 2): the MySQL branch of defaultSchema() maps the
    // connection's database to the default schema, so "app.users" listed
    // by Laravel 12 is the model table "users".
    it('treats the connection database as the default schema on MySQL (BUG-17)', function () {
        $connection = fkcMySqlConnection('app', ['app.orders', 'app.users'], [
            'orders' => [fkcForeignKey(['user_id'], 'app', 'users', ['id'])],
            'app.orders' => [fkcForeignKey(['user_id'], 'app', 'users', ['id'])],
        ]);

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns($connection, 'users'))->toBe(['id' => 'orders']);
        expect($inspector->outboundForeignKeyColumns($connection, 'orders'))->toBe(['user_id']);
        expect($inspector->outboundTarget($connection, 'orders', 'user_id'))->toBe('users');

        $edges = $inspector->edgesTouching($connection, 'users', 'id');
        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_table'])->toBe('orders');
        expect($edges[0]['parent_table'])->toBe('users');
        expect($edges[0]['child_key'])->toBe($inspector->tableKey($connection, 'orders'));
        expect($edges[0]['parent_key'])->toBe($inspector->tableKey($connection, 'users'));
    });

    it('keeps a table of another MySQL database apart from the default one (BUG-17)', function () {
        $connection = fkcMySqlConnection('app', ['app.users', 'audit.orders'], [
            'audit.orders' => [fkcForeignKey(['user_id'], 'app', 'users', ['id'])],
        ]);

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns($connection, 'users'))->toBe(['id' => 'audit.orders']);
        expect($inspector->outboundForeignKeyColumns($connection, 'orders'))->toBe([]);
        expect($inspector->outboundForeignKeyColumns($connection, 'audit.orders'))->toBe(['user_id']);
    });

    // BUG-4 (round 2): with lower_case_table_names=1 the server lists a
    // database created as "PiiMixed" as "piimixed", while the connection
    // still reports the configured "PiiMixed".
    it('matches the MySQL database name case-insensitively (BUG-4)', function () {
        $connection = fkcMySqlConnection('PiiMixed', ['piimixed.fl_children', 'piimixed.fl_parents'], [
            'fl_children' => [fkcForeignKey(['parent_code'], 'piimixed', 'fl_parents', ['code'])],
        ]);

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns($connection, 'fl_parents'))->toBe(['code' => 'fl_children']);
        expect($inspector->outboundForeignKeyColumns($connection, 'fl_children'))->toBe(['parent_code']);
        expect($inspector->outboundTarget($connection, 'fl_children', 'parent_code'))->toBe('fl_parents');
        expect($inspector->edgesTouching($connection, 'fl_parents', 'code'))->toHaveCount(1);
        expect($inspector->tableKey($connection, 'PiiMixed.fl_parents'))->toBe($inspector->tableKey($connection, 'fl_parents'));
    });

    it('matches MySQL column names case-insensitively (BUG-7)', function () {
        $connection = fkcMySqlConnection('app', ['app.orders', 'app.users'], [
            'orders' => [fkcForeignKey(['User_Code'], 'app', 'users', ['Code'])],
        ]);

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns($connection, 'users'))->toBe(['code' => 'orders']);
        expect($inspector->outboundForeignKeyColumns($connection, 'orders'))->toBe(['user_code']);
        expect($inspector->outboundTarget($connection, 'orders', 'USER_CODE'))->toBe('users');
        expect($inspector->edgesTouching($connection, 'users', 'code'))->toHaveCount(1);
        expect($inspector->edgesTouching($connection, 'orders', 'user_code'))->toHaveCount(1);
        expect($inspector->columnKey($connection, 'User_Code'))->toBe('user_code');
    });

    it('leaves PostgreSQL names exactly as the catalog reports them', function () {
        // "Parents" (quoted when created) and parents are two tables on PostgreSQL.
        $connection = Mockery::mock(PostgresConnection::class);
        $connection->shouldReceive('getName')->andReturn('pg_stub');
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('scalar')->with('select current_schema()')->andReturn('public');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('getSchemaBuilder')->andReturn(fkcBuilder(['public.Parents', 'public.children', 'public.parents'], [
            // A table of the default schema is asked for by its bare name.
            'children' => [fkcForeignKey(['parent_code'], 'public', 'Parents', ['Code'])],
        ]));

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns($connection, 'parents'))->toBe([]);
        expect($inspector->inboundReferencedColumns($connection, 'Parents'))->toBe(['Code' => 'children']);
        expect($inspector->edgesTouching($connection, 'Parents', 'code'))->toBe([]);
        expect($inspector->edgesTouching($connection, 'Parents', 'Code'))->toHaveCount(1);
        expect($inspector->columnKey($connection, 'Code'))->toBe('Code');
    });

    // BUG-7 (round 2): SQLite accepts a REFERENCES clause whose table or
    // column is written in another case than the real one.
    it('sees a SQLite foreign key whose referenced table and column differ in case (BUG-7)', function () {
        DB::statement('create table "fkc_parents" ("id" integer primary key, "code" varchar unique, "ref" varchar unique)');
        DB::statement('create table "fkc_children" ("id" integer primary key, "parent_code" varchar, "Parent_Ref" varchar, foreign key ("PARENT_CODE") references "FKC_Parents" ("code"), foreign key ("parent_ref") references "fkc_parents" ("REF"))');

        $inspector = new ForeignKeyInspector;

        expect($inspector->inboundReferencedColumns(DB::connection(), 'fkc_parents'))->toEqual(['code' => 'fkc_children', 'ref' => 'fkc_children']);
        expect($inspector->outboundForeignKeyColumns(DB::connection(), 'fkc_children'))->toEqualCanonicalizing(['parent_code', 'parent_ref']);
        expect($inspector->outboundTarget(DB::connection(), 'fkc_children', 'parent_code'))->toBe('FKC_Parents');
        expect($inspector->edgesTouching(DB::connection(), 'fkc_parents', 'code'))->toHaveCount(1);
        expect($inspector->edgesTouching(DB::connection(), 'fkc_children', 'Parent_Ref'))->toHaveCount(1);
    });

    it('refuses at boot to rewrite a SQLite column referenced in another letter case (BUG-7)', function () {
        DB::statement('create table "fkc_parents" ("id" integer primary key, "code" varchar unique)');
        DB::statement('create table "fkc_children" ("id" integer primary key, "parent_code" varchar, foreign key ("parent_code") references "Fkc_Parents" ("Code"))');

        expect(fn () => app(SchemaGuard::class)->assertSafe(new FkcSanitizer(['code' => 'word']), FkcParent::class))
            ->toThrow(UnsafeColumnException::class, UnsafeColumnException::inboundReference(FkcParent::class, 'code', FkcSanitizer::class, 'fkc_parents', 'fkc_children')->getMessage());

        expect(fn () => app(SchemaGuard::class)->assertSafe(new FkcSanitizer(['parent_code' => 'word']), FkcChild::class))
            ->toThrow(UnsafeColumnException::class, 'is a foreign key on table "fkc_children"');
    });

    it('refuses at boot to rewrite a mixed-case SQLite column referenced in lower case (BUG-7)', function () {
        DB::statement('create table "fkc_owners" ("id" integer primary key, "Name" varchar unique)');
        DB::statement('create table "fkc_parents" ("id" integer primary key, "Code" varchar unique, "Owner" varchar references "FKC_OWNERS" ("NAME"))');
        DB::statement('create table "fkc_children" ("id" integer primary key, "parent_code" varchar, foreign key ("parent_code") references "fkc_parents" ("code"))');

        expect(fn () => app(SchemaGuard::class)->assertSafe(new FkcSanitizer(['Code' => 'word']), FkcParent::class))
            ->toThrow(UnsafeColumnException::class, UnsafeColumnException::inboundReference(FkcParent::class, 'Code', FkcSanitizer::class, 'fkc_parents', 'fkc_children')->getMessage());

        expect(fn () => app(SchemaGuard::class)->assertSafe(new FkcSanitizer(['Owner' => 'word']), FkcParent::class))
            ->toThrow(UnsafeColumnException::class, UnsafeColumnException::outboundForeignKey(FkcParent::class, 'Owner', FkcSanitizer::class, 'fkc_parents', 'FKC_OWNERS')->getMessage());
    });

    it('closes a mirror group over a SQLite foreign key written in another letter case (BUG-7)', function () {
        config()->set('pii.keyed.key', str_repeat("\x04", 32));

        DB::statement('create table "fkc_parents" ("id" integer primary key, "code" varchar unique)');
        DB::statement('create table "fkc_children" ("id" integer primary key, "Parent_Code" varchar, foreign key ("PARENT_CODE") references "Fkc_Parents" ("Code"))');

        $parentTarget = ['label' => FkcParent::class, 'model' => new FkcParent, 'sanitizer' => new FkcSanitizer(['code' => fkcKeyed()], ['code' => ['fkc_children.Parent_Code']])];
        $childTarget = ['label' => FkcChild::class, 'model' => new FkcChild, 'sanitizer' => new FkcSanitizer(['Parent_Code' => fkcKeyed()])];

        // Both ends declared: the edge is covered and enforcement is suspended for it.
        expect(app(ReferencedColumnGuard::class)->assertGroups([$parentTarget, $childTarget]))->toHaveCount(1);

        // The parent alone in a group: the edge to the child is not covered.
        DB::statement('create table "fkc_tickets" ("id" integer primary key, "holder" varchar)');

        $ticketParent = ['label' => FkcParent::class, 'model' => new FkcParent, 'sanitizer' => new FkcSanitizer(['code' => fkcKeyed()], ['code' => ['fkc_tickets.holder']])];
        $ticket = ['label' => 'table:fkc_tickets', 'model' => TableRow::forTable('fkc_tickets'), 'sanitizer' => new FkcSanitizer(['holder' => fkcKeyed()])];

        expect(fn () => app(ReferencedColumnGuard::class)->assertGroups([$ticketParent, $ticket]))
            ->toThrow(UnsafeColumnException::class, 'fkc_children.Parent_Code -> Fkc_Parents.Code');
    });
    // BUG-14 (round 2): reset() also forgets the default schema, which on
    // PostgreSQL follows the search path and can change between two runs.
    it('reads the default schema again after a reset', function () {
        $schema = 'public';

        $connection = Mockery::mock(PostgresConnection::class);
        $connection->shouldReceive('getName')->andReturn('pg_stub');
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('scalar')->with('select current_schema()')->andReturnUsing(function () use (&$schema) {
            return $schema;
        });
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('getSchemaBuilder')->andReturn(fkcBuilder(['hr.orders', 'hr.users', 'public.users'], [
            'hr.orders' => [fkcForeignKey(['user_id'], 'hr', 'users', ['id'])],
            'orders' => [fkcForeignKey(['user_id'], 'hr', 'users', ['id'])],
        ]));

        $inspector = new ForeignKeyInspector;

        expect($inspector->canonicalTableName($connection, 'hr.users'))->toBe('hr.users');

        $schema = 'hr';
        $inspector->reset();

        expect($inspector->canonicalTableName($connection, 'hr.users'))->toBe('users');
        expect($inspector->inboundReferencedColumns($connection, 'users'))->toBe(['id' => 'orders']);
    });
}
