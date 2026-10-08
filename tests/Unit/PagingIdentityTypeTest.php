<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\SQLiteConnection;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    /** Default model key `id`; the tests decide whether the table has one. */
    class PitRow extends Model
    {
        protected $table = 'pit_rows';

        protected $guarded = [];

        public $timestamps = false;
    }

    /**
     * A SQLite connection that reports the mysql driver, so the resolver
     * takes its MySQL branches while the probes still run on SQLite.
     */
    class PitMysqlLikeConnection extends SQLiteConnection
    {
        public function getDriverName()
        {
            return 'mysql';
        }
    }

    class PitMysqlRow extends Model
    {
        protected $connection = 'pit_mysql_like';

        protected $table = 'pit_rows';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PitEmailSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => fn () => 'clean@x.test'];
        }
    }

    /**
     * @param  list<string>  $pagingKey
     */
    function pitSanitizer(array $fields, array $pagingKey = []): Sanitizer
    {
        return new class($fields, $pagingKey) extends Sanitizer
        {
            public function __construct(private array $f, private array $p) {}

            public function fields(): array
            {
                return $this->f;
            }

            public function pagingKey(): array
            {
                return $this->p;
            }
        };
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraints;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;
    use Shahirul22\LaravelPiiSanitizer\PagingKeyResolver;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    /**
     * Reports the given columns with the given family and native type, the
     * way the MySQL schema would; every other column as SQLite reports it.
     *
     * @param  array<string, array{0: string, 1: string}>  $overrides  column => [family, native type]
     */
    function pitReportColumnsAs(array $overrides): void
    {
        app()->instance(ColumnConstraintInspector::class, new class(app('db'), $overrides) extends ColumnConstraintInspector
        {
            /** @param array<string, array{0: string, 1: string}> $overrides */
            public function __construct($db, private array $overrides)
            {
                parent::__construct($db);
            }

            public function constraintsFor(string $table, ?string $connection = null): array
            {
                $constraints = parent::constraintsFor($table, $connection);

                foreach ($this->overrides as $column => [$family, $nativeType]) {
                    $constraints[$column] = new ColumnConstraints($column, $family, null, null, false, $nativeType);
                }

                return $constraints;
            }
        });
    }

    function pitMysqlLike(): void
    {
        DB::extend('pit_mysql_like', fn (array $config, string $name) => new PitMysqlLikeConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));
        config()->set('database.connections.pit_mysql_like', ['driver' => 'pit_mysql_like', 'database' => ':memory:', 'prefix' => '']);
    }

    // --- binary identities (BUG-21) ---

    it('refuses a SQLite blob primary key as the paging identity and names the column (BUG-21)', function () {
        DB::statement('create table "pit_rows" ("id" blob primary key not null, "email" varchar not null)');
        DB::table('pit_rows')->insert([['id' => "\x01\x02", 'email' => 'a@x.test'], ['id' => "\x03\x04", 'email' => 'b@x.test']]);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitRow, pitSanitizer(['email' => 'safeEmail'])))
            ->toThrow(UnpageableTableException::class, 'Cannot page pit_rows (PitRow, sanitized by ');

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitRow, pitSanitizer(['email' => 'safeEmail'])))
            ->toThrow(UnpageableTableException::class, ': column $id is binary, and its value cannot be bound back to find the same row on this database.');
    });

    it('fails the run at boot for a blob primary key table, before any row is read (BUG-21)', function () {
        DB::statement('create table "pit_rows" ("id" blob primary key not null, "email" varchar not null)');
        DB::table('pit_rows')->insert([['id' => "\x01", 'email' => 'a@x.test'], ['id' => "\x02", 'email' => 'b@x.test'], ['id' => "\x03", 'email' => 'c@x.test']]);

        config()->set('pii.models', []);
        config()->set('pii.tables', ['pit_rows' => PitEmailSanitizer::class]);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2)))
            ->toThrow(UnpageableTableException::class, 'column $id is binary');

        expect(DB::table('pit_rows')->orderBy('email')->pluck('email')->all())->toBe(['a@x.test', 'b@x.test', 'c@x.test']);
    });

    it('pages a blob primary key table on another unique column instead (BUG-21)', function () {
        DB::statement('create table "pit_rows" ("id" blob primary key not null, "code" varchar not null, "email" varchar not null)');
        DB::statement('create unique index "pit_rows_code_unique" on "pit_rows" ("code")');
        DB::table('pit_rows')->insert([['id' => "\x01", 'code' => 'c1', 'email' => 'a@x.test'], ['id' => "\x02", 'code' => 'c2', 'email' => 'b@x.test']]);

        $key = app(PagingKeyResolver::class)->resolve(new PitRow, pitSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('unique');
        expect($key->columns)->toBe(['code']);
    });

    it('refuses a blob column in a unique index or a declared pagingKey() (BUG-21)', function () {
        Schema::create('pit_rows', function ($table) {
            $table->binary('token');
            $table->string('email');
            $table->unique('token');
        });

        DB::table('pit_rows')->insert([['token' => "\x01", 'email' => 'a@x.test'], ['token' => "\x02", 'email' => 'b@x.test']]);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitRow, pitSanitizer(['email' => 'safeEmail'], ['token'])))
            ->toThrow(UnpageableTableException::class, 'column $token is binary');
    });

    // --- MySQL / MariaDB enum identities (BUG-20) ---

    it('refuses a MySQL enum column in a primary key and names the column (BUG-20)', function () {
        pitMysqlLike();
        DB::connection('pit_mysql_like')->statement('create table "pit_rows" ("role" varchar not null primary key, "note" varchar not null)');
        DB::connection('pit_mysql_like')->table('pit_rows')->insert([['role' => 'viewer', 'note' => 'a'], ['role' => 'admin', 'note' => 'b']]);
        pitReportColumnsAs(['role' => ['enum', "enum('viewer','editor','admin')"]]);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitMysqlRow, pitSanitizer(['note' => 'word'])))
            ->toThrow(UnpageableTableException::class, 'column $role is an ENUM, which MySQL and MariaDB sort by member position but compare as text, so key-set pages would skip and repeat rows.');
    });

    it('leaves a MySQL enum column out of the keyless fallback identity and names it when nothing else is unique (BUG-20)', function () {
        pitMysqlLike();
        DB::connection('pit_mysql_like')->statement('create table "pit_rows" ("user_id" integer not null, "role" varchar not null, "note" varchar not null)');
        DB::connection('pit_mysql_like')->table('pit_rows')->insert([
            ['user_id' => 1, 'role' => 'viewer', 'note' => 'a'],
            ['user_id' => 1, 'role' => 'admin', 'note' => 'b'],
        ]);
        pitReportColumnsAs(['role' => ['enum', "enum('viewer','editor','admin')"]]);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitMysqlRow, pitSanitizer(['note' => 'word'])))
            ->toThrow(UnpageableTableException::class, 'column $role is an ENUM');
    });

    it('refuses a MySQL enum column in a unique index and a declared pagingKey() (BUG-20)', function () {
        pitMysqlLike();
        DB::connection('pit_mysql_like')->statement('create table "pit_rows" ("role" varchar not null, "note" varchar not null)');
        DB::connection('pit_mysql_like')->statement('create unique index "pit_rows_role_unique" on "pit_rows" ("role")');
        DB::connection('pit_mysql_like')->table('pit_rows')->insert([['role' => 'viewer', 'note' => 'a'], ['role' => 'admin', 'note' => 'b']]);
        pitReportColumnsAs(['role' => ['enum', "enum('viewer','editor','admin')"]]);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitMysqlRow, pitSanitizer(['note' => 'word'], ['role'])))
            ->toThrow(UnpageableTableException::class, 'column $role is an ENUM');
    });

    // --- MySQL / MariaDB single-precision float identities (BUG-22) ---

    it('refuses a MySQL single-precision FLOAT primary key and names the column (BUG-22)', function () {
        pitMysqlLike();
        DB::connection('pit_mysql_like')->statement('create table "pit_rows" ("code" real not null primary key, "note" varchar not null)');
        DB::connection('pit_mysql_like')->table('pit_rows')->insert([['code' => 0.1, 'note' => 'a'], ['code' => 0.2, 'note' => 'b']]);
        pitReportColumnsAs(['code' => ['decimal', 'float']]);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitMysqlRow, pitSanitizer(['note' => 'word'])))
            ->toThrow(UnpageableTableException::class, 'column $code is a single-precision FLOAT, which MySQL and MariaDB return as a rounded value that never equals the stored one.');
    });

    it('refuses a MySQL FLOAT column in a unique index and a declared pagingKey() (BUG-22)', function () {
        pitMysqlLike();
        DB::connection('pit_mysql_like')->statement('create table "pit_rows" ("code" real not null, "note" varchar not null)');
        DB::connection('pit_mysql_like')->statement('create unique index "pit_rows_code_unique" on "pit_rows" ("code")');
        DB::connection('pit_mysql_like')->table('pit_rows')->insert([['code' => 0.1, 'note' => 'a'], ['code' => 0.2, 'note' => 'b']]);
        pitReportColumnsAs(['code' => ['decimal', 'float unsigned']]);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PitMysqlRow, pitSanitizer(['note' => 'word'], ['code'])))
            ->toThrow(UnpageableTableException::class, 'column $code is a single-precision FLOAT');
    });

    it('still accepts a MySQL DOUBLE primary key (BUG-22)', function () {
        pitMysqlLike();
        DB::connection('pit_mysql_like')->statement('create table "pit_rows" ("code" real not null primary key, "note" varchar not null)');
        pitReportColumnsAs(['code' => ['decimal', 'double']]);

        $key = app(PagingKeyResolver::class)->resolve(new PitMysqlRow, pitSanitizer(['note' => 'word']));

        expect($key->source)->toBe('primary');
        expect($key->columns)->toBe(['code']);
    });
}
