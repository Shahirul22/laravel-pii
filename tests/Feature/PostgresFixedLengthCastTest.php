<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\SQLiteConnection;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    /**
     * An in-memory SQLite connection that reports the pgsql driver name, so
     * the runner takes its PostgreSQL branches while the SQL still runs on
     * SQLite. SQLite cannot show the truncation itself; this pins that a
     * character(n) column reaches the batched UPDATE as the unbounded bpchar
     * cast, which a real PostgreSQL stores in full.
     */
    class PflcPgLikeConnection extends SQLiteConnection
    {
        public function getDriverName()
        {
            return 'pgsql';
        }
    }

    class PflcCode extends Model
    {
        protected $connection = 'pflc_pg_like';

        protected $table = 'pflc_codes';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PflcCodeSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => fn () => 'ABCDEFGH'];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    it('casts a character(n) value to the unbounded bpchar, never to character(1) (BUG-1)', function () {
        DB::extend('pflc_pg_like', fn (array $config, string $name) => new PflcPgLikeConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));
        config()->set('database.connections.pflc_pg_like', ['driver' => 'pflc_pg_like', 'database' => ':memory:', 'prefix' => '']);

        DB::connection('pflc_pg_like')->statement('create table "pflc_codes" ("id" integer primary key autoincrement not null, "code" character(10) not null)');
        DB::connection('pflc_pg_like')->table('pflc_codes')->insert([['code' => 'one'], ['code' => 'two']]);

        config()->set('pii.sanitizers', [PflcCode::class => PflcCodeSanitizer::class]);
        config()->set('pii.models', [PflcCode::class]);

        $updates = [];
        DB::connection('pflc_pg_like')->listen(function ($query) use (&$updates) {
            if (str_starts_with($query->sql, 'UPDATE')) {
                $updates[] = $query->sql;
            }
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($updates)->toBe([
            'UPDATE "pflc_codes" SET "code" = CASE "id" WHEN ? THEN CAST(? AS bpchar) WHEN ? THEN CAST(? AS bpchar) END WHERE "id" IN (?, ?)',
        ]);
        expect(DB::connection('pflc_pg_like')->table('pflc_codes')->orderBy('id')->pluck('code')->all())->toBe(['ABCDEFGH', 'ABCDEFGH']);
    });
}
