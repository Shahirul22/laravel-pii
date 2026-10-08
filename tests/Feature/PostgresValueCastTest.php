<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\SQLiteConnection;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    /**
     * An in-memory SQLite connection that reports the pgsql driver name, so
     * the runner takes its PostgreSQL branches while the SQL still runs on
     * SQLite (which accepts CAST(? AS integer) and friends). CI has no
     * PostgreSQL server; this pins the wiring from the schema to the
     * batched UPDATE.
     */
    class PvcPgLikeConnection extends SQLiteConnection
    {
        public function getDriverName()
        {
            return 'pgsql';
        }
    }

    class PvcPerson extends Model
    {
        protected $connection = 'pvc_pg_like';

        protected $table = 'pvc_people';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PvcPersonSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['age' => fn () => 42, 'name' => fn ($value) => 'clean-'.$value];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    it('casts every bound value to the column type in the batched UPDATE on a pgsql connection (BUG-34)', function () {
        DB::extend('pvc_pg_like', fn (array $config, string $name) => new PvcPgLikeConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));
        config()->set('database.connections.pvc_pg_like', ['driver' => 'pvc_pg_like', 'database' => ':memory:', 'prefix' => '']);

        Schema::connection('pvc_pg_like')->create('pvc_people', function ($table) {
            $table->id();
            $table->integer('age');
            $table->string('name');
        });

        DB::connection('pvc_pg_like')->table('pvc_people')->insert([['age' => 1, 'name' => 'ann'], ['age' => 2, 'name' => 'bob']]);

        config()->set('pii.sanitizers', [PvcPerson::class => PvcPersonSanitizer::class]);
        config()->set('pii.models', [PvcPerson::class]);

        $updates = [];
        DB::connection('pvc_pg_like')->listen(function ($query) use (&$updates) {
            if (str_starts_with($query->sql, 'UPDATE')) {
                $updates[] = $query->sql;
            }
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($updates)->toBe([
            'UPDATE "pvc_people" SET "age" = CASE "id" WHEN ? THEN CAST(? AS integer) WHEN ? THEN CAST(? AS integer) END, "name" = CASE "id" WHEN ? THEN CAST(? AS varchar) WHEN ? THEN CAST(? AS varchar) END WHERE "id" IN (?, ?)',
        ]);
        expect(DB::connection('pvc_pg_like')->table('pvc_people')->orderBy('id')->get()->map(fn ($row) => [$row->age, $row->name])->all())
            ->toBe([[42, 'clean-ann'], [42, 'clean-bob']]);
    });
}
