<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\DB;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class DryRunUser extends Model
    {
        protected $table = 'dry_run_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class DryRunUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
                'email' => 'safeEmail',
            ];
        }
    }

    class DryRunStatusUser extends Model
    {
        protected $table = 'dry_run_status_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class DryRunStatusUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'status' => 'active',
            ];
        }
    }

    class DryRunTxLevelUser extends Model
    {
        protected $table = 'dry_run_tx_level_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class DryRunTxLevelUserSanitizer extends Sanitizer
    {
        public static ?int $observedLevel = null;

        public function fields(): array
        {
            return [
                'name' => function ($value, $faker, $row) {
                    self::$observedLevel = DB::transactionLevel();

                    return $faker->name();
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
        Schema::create('dry_run_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('role')->default('member');
        });

        for ($i = 0; $i < 25; $i++) {
            DB::table('dry_run_users')->insert([
                'name' => "Seed Name {$i}",
                'email' => "seed-{$i}@example.com",
                'role' => $i % 2 === 0 ? 'admin' : 'member',
            ]);
        }

        config()->set('pii.sanitizers', [
            DryRunUser::class => DryRunUserSanitizer::class,
        ]);
        config()->set('pii.models', [DryRunUser::class]);
    });

    function dryRunUsersSnapshot()
    {
        return DB::table('dry_run_users')->orderBy('id')->get();
    }

    it('modifies no row during a dry run', function () {
        $before = dryRunUsersSnapshot();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));

        $after = dryRunUsersSnapshot();

        expect($after)->toEqual($before);
    });

    it('reports the dry-run flag and the exact report shape', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));

        expect($report->dryRun)->toBeTrue();
        expect($report->failed())->toBeFalse();
        expect($report->models)->toHaveCount(1);

        $model = $report->models[0];

        expect($model->rowsScanned)->toBe(25);
        expect($model->chunkSize)->toBe(10);
        expect($model->automaticChunkSize)->toBeFalse();
        expect($model->expectedChunks)->toBe(3);
        expect($model->chunks)->toHaveCount(3);

        foreach ($model->chunks as $chunk) {
            expect($chunk->status)->toBe(ChunkStatus::Completed);
        }
    });

    it('reports per-column counts of the rows that would change', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));

        $model = $report->models[0];

        expect($model->columnCounts)->toHaveKeys(['name', 'email']);
        expect(array_keys($model->columnCounts))->toHaveCount(2);
        expect($model->columnCounts['name'])->toBe(25);
        expect($model->columnCounts['email'])->toBe(25);
        // In dry-run mode this reads as "rows that would be sanitized".
        expect($model->rowsSanitized())->toBe(25);
    });

    it('counts only rows whose generated value actually differs', function () {
        Schema::create('dry_run_status_users', function ($table) {
            $table->id();
            $table->string('status');
        });

        for ($i = 0; $i < 10; $i++) {
            DB::table('dry_run_status_users')->insert([
                'status' => $i < 4 ? 'active' : 'inactive',
            ]);
        }

        config()->set('pii.sanitizers', [
            DryRunStatusUser::class => DryRunStatusUserSanitizer::class,
        ]);
        config()->set('pii.models', [DryRunStatusUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));

        $model = $report->models[0];

        expect($model->columnCounts['status'])->toBe(6);
    });

    it('opens no transaction during a dry run', function () {
        Schema::create('dry_run_tx_level_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::table('dry_run_tx_level_users')->insert(['name' => 'seed']);

        config()->set('pii.sanitizers', [
            DryRunTxLevelUser::class => DryRunTxLevelUserSanitizer::class,
        ]);
        config()->set('pii.models', [DryRunTxLevelUser::class]);

        DryRunTxLevelUserSanitizer::$observedLevel = null;
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));
        expect(DryRunTxLevelUserSanitizer::$observedLevel)->toBe(0);

        DryRunTxLevelUserSanitizer::$observedLevel = null;
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: false));
        expect(DryRunTxLevelUserSanitizer::$observedLevel)->toBe(1);
    });

    it('populates columnCounts in a real run too', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: false));

        $model = $report->models[0];

        expect($model->columnCounts['name'])->toBe(25);
        expect($model->columnCounts['email'])->toBe(25);
    });

    it('leaves no residue after a dry run', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: false));

        expect($report->failed())->toBeFalse();

        $after = dryRunUsersSnapshot();

        foreach ($after as $row) {
            expect($row->name)->not->toStartWith('Seed Name');
            expect($row->email)->not->toStartWith('seed-');
        }
    });
}
