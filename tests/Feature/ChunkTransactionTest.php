<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class TxUser extends Model
    {
        protected $table = 'tx_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class TxUserSanitizer extends Sanitizer
    {
        public static int $seen = 0;

        public function fields(): array
        {
            return [
                'email' => function ($value, $faker, $row) {
                    self::$seen++;

                    if (self::$seen === 12) {
                        throw new RuntimeException('boom');
                    }

                    return $faker->safeEmail();
                },
            ];
        }
    }

    class TxSecondUser extends Model
    {
        protected $table = 'tx_second_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class TxSecondUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'email' => 'safeEmail',
            ];
        }
    }

    class TxFkUser extends Model
    {
        protected $table = 'tx_fk_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class TxFkUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'account_id' => 'randomNumber',
            ];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        Schema::create('tx_users', function ($table) {
            $table->id();
            $table->string('email');
        });

        for ($i = 0; $i < 25; $i++) {
            DB::table('tx_users')->insert(['email' => "seed-{$i}@example.com"]);
        }

        TxUserSanitizer::$seen = 0;

        config()->set('pii.sanitizers', [
            TxUser::class => TxUserSanitizer::class,
        ]);
        config()->set('pii.models', [TxUser::class]);
    });

    function txUsersSnapshot()
    {
        return DB::table('tx_users')->orderBy('id')->get()->keyBy('id');
    }

    it('does not throw for a mid-chunk failure', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report)->not->toBeNull();
    });

    it('rolls back the failing chunk so its rows are unmodified', function () {
        $before = txUsersSnapshot();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $after = txUsersSnapshot();

        for ($id = 11; $id <= 20; $id++) {
            expect($after[$id]->email)->toBe($before[$id]->email);
        }
    });

    it('keeps earlier chunks committed', function () {
        $before = txUsersSnapshot();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $after = txUsersSnapshot();

        for ($id = 1; $id <= 10; $id++) {
            expect($after[$id]->email)->not->toBe($before[$id]->email);
        }
    });

    it('never attempts later chunks', function () {
        $before = txUsersSnapshot();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $after = txUsersSnapshot();

        for ($id = 21; $id <= 25; $id++) {
            expect($after[$id]->email)->toBe($before[$id]->email);
        }
    });

    it('reports exactly which chunks completed and which did not', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $model = $report->models[0];

        expect($model->expectedChunks)->toBe(3);
        expect($model->chunks)->toHaveCount(2);
        expect($model->completedChunks())->toHaveCount(1);
        expect($model->rowsSanitized())->toBe(10);

        $failedChunk = $model->chunks[1];

        expect($failedChunk->status)->toBe(ChunkStatus::RolledBack);
        expect($failedChunk->failureClass)->toBe(RuntimeException::class);
        expect($failedChunk->failureMessage)->toContain(RuntimeException::class);
        expect($failedChunk->failureMessage)->not->toContain('boom');

        expect($model->chunksNotAttempted())->toBe(1);
    });

    it('does not count column changes for rows in a chunk that rolled back', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $model = $report->models[0];

        // The failing chunk (rows 11-20) touches 'email' for at least the
        // first 11 rows before throwing on the 12th `forRow` call, but the
        // whole chunk transaction rolls back — none of that should be
        // reflected in the reported columnCounts.
        expect($model->columnCounts['email'])->toBe(10);
    });

    it('marks the run and model as failed', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeTrue();
        expect($report->models[0]->failed())->toBeTrue();
    });

    it('halts the outer model loop after a failing model', function () {
        Schema::create('tx_second_users', function ($table) {
            $table->id();
            $table->string('email');
        });

        DB::table('tx_second_users')->insert(['email' => 'seed@example.com']);

        config()->set('pii.sanitizers', [
            TxUser::class => TxUserSanitizer::class,
            TxSecondUser::class => TxSecondUserSanitizer::class,
        ]);
        config()->set('pii.models', [TxUser::class, TxSecondUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->models)->toHaveCount(1);
        expect($report->models[0]->modelClass)->toBe(TxUser::class);
    });

    it('propagates resolution-time failures instead of capturing them', function () {
        Schema::create('tx_fk_users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->foreign('account_id')->references('id')->on('tx_users');
        });

        config()->set('pii.sanitizers', [
            TxFkUser::class => TxFkUserSanitizer::class,
        ]);
        config()->set('pii.models', [TxFkUser::class]);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions))
            ->toThrow(UnsafeColumnException::class);
    });
}
