<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\SQLiteConnection;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    /** A SQLite connection that reports another driver name, to pin driver-dependent SQL. */
    class KprDriverNamedConnection extends SQLiteConnection
    {
        public static string $driver = 'mysql';

        public function getDriverName()
        {
            return static::$driver;
        }
    }

    /** Composite primary key: always paged by key set. */
    class KprMember extends Model
    {
        protected $table = 'kpr_members';

        protected $guarded = [];

        public $timestamps = false;
    }

    class KprDriverMember extends Model
    {
        protected $connection = 'kpr_driver_named';

        protected $table = 'kpr_members';

        protected $guarded = [];

        public $timestamps = false;
    }

    /** A primary key with a cast: paged by key set, on the stored value. */
    class KprCastKeyMember extends Model
    {
        protected $table = 'kpr_cast_key_members';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;

        protected $keyType = 'string';

        protected function casts(): array
        {
            return ['code' => 'string'];
        }

        protected $primaryKey = 'code';
    }

    /** A string key read through a legacy get accessor (the hasGetMutator() branch). */
    class KprLegacyAccessorMember extends Model
    {
        protected $table = 'kpr_legacy_accessor_members';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;

        protected $keyType = 'string';

        protected $primaryKey = 'code';

        public function getCodeAttribute(mixed $value): string
        {
            return strtoupper((string) $value);
        }
    }

    class KprFailingSanitizer extends Sanitizer
    {
        public static int $seen = 0;

        /** The row number whose value throws, or 0 for none. */
        public static int $failAt = 0;

        /** A runaway guard: a run that pages for ever fails here instead of hanging. */
        public static int $limit = 1000;

        public function fields(): array
        {
            return [
                'name' => function ($value) {
                    self::$seen++;

                    if (self::$seen === self::$failAt) {
                        throw new RuntimeException('boom');
                    }

                    if (self::$seen > self::$limit) {
                        throw new RuntimeException('runaway paging');
                    }

                    return 'clean-'.$value;
                },
            ];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        KprFailingSanitizer::$seen = 0;
        KprFailingSanitizer::$failAt = 0;
        KprFailingSanitizer::$limit = 1000;
    });

    // BUG-16 (round 2): a failed chunk stops the run on the key-set path too,
    // not only on chunkById().
    it('stops at the first failed chunk when a composite key is paged by key set', function () {
        Schema::create('kpr_members', function ($table) {
            $table->integer('org_id');
            $table->integer('member_id');
            $table->string('name');
            $table->primary(['org_id', 'member_id']);
        });

        for ($i = 1; $i <= 8; $i++) {
            DB::table('kpr_members')->insert(['org_id' => 1, 'member_id' => $i, 'name' => "real-{$i}"]);
        }

        config()->set('pii.sanitizers', [KprMember::class => KprFailingSanitizer::class]);
        config()->set('pii.models', [KprMember::class]);

        KprFailingSanitizer::$failAt = 3;

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeTrue();

        $chunks = $report->models[0]->chunks;

        expect($chunks)->toHaveCount(2);
        expect($chunks[0]->status)->toBe(ChunkStatus::Completed);
        expect($chunks[1]->status)->toBe(ChunkStatus::RolledBack);
        expect(KprFailingSanitizer::$seen)->toBe(3);
        expect(DB::table('kpr_members')->orderBy('member_id')->pluck('name')->all())
            ->toBe(['clean-real-1', 'clean-real-2', 'real-3', 'real-4', 'real-5', 'real-6', 'real-7', 'real-8']);
    });

    it('stops at the first failed chunk when a primary key with a cast is paged by key set', function () {
        Schema::create('kpr_cast_key_members', function ($table) {
            $table->string('code')->primary();
            $table->string('name');
        });

        foreach (['aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg', 'hh'] as $code) {
            DB::table('kpr_cast_key_members')->insert(['code' => $code, 'name' => "real-{$code}"]);
        }

        config()->set('pii.sanitizers', [KprCastKeyMember::class => KprFailingSanitizer::class]);
        config()->set('pii.models', [KprCastKeyMember::class]);

        KprFailingSanitizer::$failAt = 3;

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeTrue();
        expect($report->models[0]->chunks)->toHaveCount(2);
        expect($report->models[0]->chunks[1]->status)->toBe(ChunkStatus::RolledBack);
        expect(DB::table('kpr_cast_key_members')->orderBy('code')->pluck('name')->all())
            ->toBe(['clean-real-aa', 'clean-real-bb', 'real-cc', 'real-dd', 'real-ee', 'real-ff', 'real-gg', 'real-hh']);
    });

    // BUG-18 (round 2): a legacy getCodeAttribute() accessor on the key
    // sends the table to key-set paging, which reads the stored value;
    // chunkById() would page on the upper-cased value and never finish.
    it('pages every row once when the string key has a legacy get accessor', function () {
        Schema::create('kpr_legacy_accessor_members', function ($table) {
            $table->string('code')->primary();
            $table->string('name');
        });

        foreach (['aa', 'bb', 'cc', 'dd', 'ee'] as $code) {
            DB::table('kpr_legacy_accessor_members')->insert(['code' => $code, 'name' => "real-{$code}"]);
        }

        config()->set('pii.sanitizers', [KprLegacyAccessorMember::class => KprFailingSanitizer::class]);
        config()->set('pii.models', [KprLegacyAccessorMember::class]);

        KprFailingSanitizer::$limit = 50;

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->chunks)->toHaveCount(3);
        expect(KprFailingSanitizer::$seen)->toBe(5);
        expect(DB::table('kpr_legacy_accessor_members')->orderBy('code')->pluck('name')->all())
            ->toBe(['clean-real-aa', 'clean-real-bb', 'clean-real-cc', 'clean-real-dd', 'clean-real-ee']);
    });

    // BUG-19 (round 2): the runner keeps the expanded OR predicate for a
    // composite key on MySQL (a row-value comparison is a full index scan
    // there) and uses the row-value comparison on PostgreSQL.
    it('chooses the key-set predicate for a composite key by driver', function (string $driver, string $expected, string $unexpected) {
        KprDriverNamedConnection::$driver = $driver;

        DB::extend('kpr_driver_named', fn (array $config, string $name) => new KprDriverNamedConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));
        config()->set('database.connections.kpr_driver_named', ['driver' => 'kpr_driver_named', 'database' => ':memory:', 'prefix' => '']);

        Schema::connection('kpr_driver_named')->create('kpr_members', function ($table) {
            $table->integer('org_id');
            $table->integer('member_id');
            $table->string('name');
            $table->primary(['org_id', 'member_id']);
        });

        for ($i = 1; $i <= 5; $i++) {
            DB::connection('kpr_driver_named')->table('kpr_members')->insert(['org_id' => 1, 'member_id' => $i, 'name' => "real-{$i}"]);
        }

        config()->set('pii.sanitizers', [KprDriverMember::class => KprFailingSanitizer::class]);
        config()->set('pii.models', [KprDriverMember::class]);

        $pages = [];

        DB::connection('kpr_driver_named')->listen(function ($query) use (&$pages) {
            if (str_starts_with($query->sql, 'select * from "kpr_members"') && str_contains($query->sql, ' where ')) {
                $pages[] = $query->sql;
            }
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2, dryRun: true));

        expect($report->failed())->toBeFalse();
        expect(KprFailingSanitizer::$seen)->toBe(5);
        expect($pages)->toHaveCount(2);

        foreach ($pages as $sql) {
            expect($sql)->toContain($expected);
            expect($sql)->not->toContain($unexpected);
        }
    })->with([
        'mysql: expanded OR predicate' => ['mysql', ' or ', ') > ('],
        'pgsql: row-value comparison' => ['pgsql', ') > (', ' or '],
    ]);
}
