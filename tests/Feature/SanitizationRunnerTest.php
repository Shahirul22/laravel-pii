<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\ReplacementGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class RunnerUser extends Model
    {
        protected $table = 'runner_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RunnerUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
                'email' => 'safeEmail',
            ];
        }
    }

    class RunnerPost extends Model
    {
        protected $table = 'runner_posts';

        protected $guarded = [];

        public $timestamps = false;
    }

    class SpyReplacementGenerator extends ReplacementGenerator
    {
        public static int $resetCalls = 0;

        public function reset(): void
        {
            self::$resetCalls++;

            parent::reset();
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\ReplacementGenerator;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        Schema::create('runner_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('role')->default('member');
        });

        Schema::create('runner_posts', function ($table) {
            $table->id();
            $table->string('title');
        });

        for ($i = 0; $i < 25; $i++) {
            DB::table('runner_users')->insert([
                'name' => "Seed Name {$i}",
                'email' => "seed-{$i}@example.com",
                'role' => $i % 2 === 0 ? 'admin' : 'member',
            ]);
        }

        config()->set('pii.sanitizers', [
            RunnerUser::class => RunnerUserSanitizer::class,
        ]);
        config()->set('pii.models', [RunnerUser::class]);

        SpyReplacementGenerator::$resetCalls = 0;
    });

    it('sanitizes every row of a multi-chunk table end to end', function () {
        $before = DB::table('runner_users')->orderBy('id')->get()->keyBy('id');

        $runner = app(SanitizationRunner::class);
        $report = $runner->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->models)->toHaveCount(1);

        $model = $report->models[0];

        expect($model->chunkSize)->toBe(10);
        expect($model->automaticChunkSize)->toBeFalse();
        expect($model->expectedChunks)->toBe(3);
        expect($model->chunks)->toHaveCount(3);
        foreach ($model->chunks as $chunk) {
            expect($chunk->status)->toBe(ChunkStatus::Completed);
        }
        expect($model->rowsScanned)->toBe(25);
        expect($model->rowsSanitized())->toBe(25);

        $after = DB::table('runner_users')->orderBy('id')->get()->keyBy('id');

        expect($after)->toHaveCount($before->count());
        expect($after->keys()->all())->toBe($before->keys()->all());

        foreach ($after as $id => $row) {
            expect($row->name)->not->toBe($before[$id]->name);
            expect($row->email)->not->toBe($before[$id]->email);
        }
    });

    it('uses the automatic chunk size when none is given', function () {
        $runner = app(SanitizationRunner::class);
        $report = $runner->run(new RunOptions);

        $model = $report->models[0];

        expect($model->automaticChunkSize)->toBeTrue();
        expect($model->chunkSize)->toBe(25);
        expect($model->chunks)->toHaveCount(1);
        expect($model->chunks[0]->status)->toBe(ChunkStatus::Completed);
    });

    it('leaves undeclared columns byte-identical', function () {
        $before = DB::table('runner_users')->orderBy('id')->pluck('role', 'id');

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $after = DB::table('runner_users')->orderBy('id')->pluck('role', 'id');

        expect($after->all())->toBe($before->all());
    });

    it('skips a model with no sanitizer instead of erroring', function () {
        config()->set('pii.models', [RunnerUser::class, RunnerPost::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->models)->toHaveCount(2);

        $postReport = collect($report->models)->firstWhere('modelClass', RunnerPost::class);

        expect($postReport->skipReason)->toBe('no-sanitizer');
        expect($postReport->chunks)->toBe([]);
    });

    it('returns an empty report when no models are configured', function () {
        config()->set('pii.models', []);

        $report = app(SanitizationRunner::class)->run(new RunOptions);

        expect($report->models)->toBe([]);
        expect($report->failed())->toBeFalse();
    });

    it('honors an explicit model list over the configured one', function () {
        config()->set('pii.models', [RunnerPost::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(models: [RunnerUser::class], chunkSize: 10));

        expect($report->models)->toHaveCount(1);
        expect($report->models[0]->modelClass)->toBe(RunnerUser::class);
    });

    it('resets the replacement generator exactly once per run', function () {
        app()->bind(ReplacementGenerator::class, fn ($app) => $app->make(SpyReplacementGenerator::class));

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect(SpyReplacementGenerator::$resetCalls)->toBe(1);
    });

    it('records first and last key per chunk', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $model = $report->models[0];

        expect($model->chunks[0]->firstKey)->toBe(1);
        expect($model->chunks[0]->lastKey)->toBe(10);
        expect($model->chunks[1]->firstKey)->toBe(11);
        expect($model->chunks[1]->lastKey)->toBe(20);
        expect($model->chunks[2]->firstKey)->toBe(21);
        expect($model->chunks[2]->lastKey)->toBe(25);
    });

    it('exposes no connection-selection surface', function () {
        $ctor = new ReflectionMethod(RunOptions::class, '__construct');
        $paramNames = array_map(fn (ReflectionParameter $p) => $p->getName(), $ctor->getParameters());

        expect($paramNames)->not->toContain('connection');
        expect($paramNames)->not->toContain('database');

        $config = require __DIR__.'/../../config/pii.php';

        expect(array_key_exists('connection', $config))->toBeFalse();
    });
}
