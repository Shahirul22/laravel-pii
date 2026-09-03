<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\ChunkSizer;
    use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
    use Shahirul22\LaravelPiiSanitizer\ReplacementGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\SanitizerResolver;

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

    class RunnerJsonUser extends Model
    {
        protected $table = 'runner_json_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RunnerJsonUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'metadata' => fn () => ['redacted' => true, 'tags' => ['a', 'b']],
            ];
        }
    }

    class RunnerTimestampedUser extends Model
    {
        protected $table = 'runner_timestamped_users';

        protected $guarded = [];
    }

    class RunnerTimestampedUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'email' => 'safeEmail',
            ];
        }
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

    class RunnerNotAModel
    {
        //
    }

    class SpyChunkSizer extends ChunkSizer
    {
        public static int $resetCalls = 0;

        public function reset(): void
        {
            self::$resetCalls++;

            parent::reset();
        }
    }

    class SpySanitizerResolver implements SanitizerResolverContract
    {
        /** @var array<string, int> */
        public static array $resolveCallsByModel = [];

        public function __construct(private readonly SanitizerResolver $real) {}

        public function resolve(Model|string $model): ?Sanitizer
        {
            $class = is_object($model) ? $model::class : $model;
            self::$resolveCallsByModel[$class] = (self::$resolveCallsByModel[$class] ?? 0) + 1;

            return $this->real->resolve($model);
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkSizer;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\ReplacementGenerator;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\SanitizerResolver;

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
        SpyChunkSizer::$resetCalls = 0;
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

    it('JSON-encodes an array replacement value instead of writing the literal string "Array"', function () {
        Schema::create('runner_json_users', function ($table) {
            $table->id();
            $table->text('metadata')->nullable();
        });

        DB::table('runner_json_users')->insert(['metadata' => json_encode(['real' => 'pii-value'])]);

        config()->set('pii.sanitizers', [
            RunnerJsonUser::class => RunnerJsonUserSanitizer::class,
        ]);
        config()->set('pii.models', [RunnerJsonUser::class]);

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $row = DB::table('runner_json_users')->first();

        expect($row->metadata)->not->toBe('Array');
        expect(json_decode($row->metadata, true))->toBe(['redacted' => true, 'tags' => ['a', 'b']]);
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

    it('resets the chunk sizer exactly once per run', function () {
        app()->bind(ChunkSizer::class, fn ($app) => $app->make(SpyChunkSizer::class));

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect(SpyChunkSizer::$resetCalls)->toBe(1);
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

    it('cannot be subclassed, and its per-model entry point is not reachable except through run()', function () {
        $class = new ReflectionClass(SanitizationRunner::class);

        expect($class->isFinal())->toBeTrue();

        $method = $class->getMethod('runModel');

        expect($method->isPrivate())->toBeTrue();
    });

    it('exposes no connection-selection surface', function () {
        $ctor = new ReflectionMethod(RunOptions::class, '__construct');
        $paramNames = array_map(fn (ReflectionParameter $p) => $p->getName(), $ctor->getParameters());

        expect($paramNames)->not->toContain('connection');
        expect($paramNames)->not->toContain('database');

        $config = require __DIR__.'/../../config/pii.php';

        expect(array_key_exists('connection', $config))->toBeFalse();
    });

    it('throws a named exception for a non-Model entry in pii.models, before any earlier model is written', function () {
        config()->set('pii.models', [RunnerUser::class, RunnerNotAModel::class]);

        $before = DB::table('runner_users')->orderBy('id')->pluck('name', 'id');

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow(InvalidConfigurationException::class, RunnerNotAModel::class);

        $after = DB::table('runner_users')->orderBy('id')->pluck('name', 'id');

        expect($after->all())->toBe($before->all());
    });

    it('throws a named exception when pii.models is not an array, instead of a raw TypeError', function () {
        config()->set('pii.models', 'not-an-array');

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('throws a named exception when pii.models contains a non-string entry, instead of a raw TypeError', function () {
        config()->set('pii.models', [RunnerUser::class, 42]);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('leaves real timestamp columns byte-identical after a sanitize run (R2.3/AC-3)', function () {
        Schema::create('runner_timestamped_users', function ($table) {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });

        $createdAt = '2020-01-01 00:00:00';
        $updatedAt = '2020-01-02 00:00:00';

        DB::table('runner_timestamped_users')->insert([
            'email' => 'seed@example.com',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        config()->set('pii.sanitizers', [
            RunnerTimestampedUser::class => RunnerTimestampedUserSanitizer::class,
        ]);
        config()->set('pii.models', [RunnerTimestampedUser::class]);

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $row = DB::table('runner_timestamped_users')->first();

        expect($row->email)->not->toBe('seed@example.com');
        expect($row->created_at)->toBe($createdAt);
        expect($row->updated_at)->toBe($updatedAt);
    });

    it('resolves each model\'s sanitizer exactly once per run, not once in registration plus again in runModel', function () {
        SpySanitizerResolver::$resolveCallsByModel = [];

        app()->forgetInstance(SanitizerResolverContract::class);
        app()->singleton(SanitizerResolverContract::class, fn ($app) => new SpySanitizerResolver($app->make(SanitizerResolver::class)));

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect(SpySanitizerResolver::$resolveCallsByModel[RunnerUser::class] ?? 0)->toBe(1);
    });

    it('sub-batches a single large chunk into multiple UPDATE statements instead of one with too many bound parameters', function () {
        // runner_users has 2 declared columns (name, email); at 2 bound
        // parameters per column per row (CASE/WHEN pairs) plus 1 per row for
        // WHERE...IN, a single 200-row chunk would need 1000 placeholders —
        // over the internal per-statement ceiling — and must split into more
        // than one UPDATE statement.
        DB::table('runner_users')->delete();

        for ($i = 0; $i < 200; $i++) {
            DB::table('runner_users')->insert([
                'name' => "Seed Name {$i}",
                'email' => "seed-{$i}@example.com",
                'role' => 'member',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 200));

        $updateQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_starts_with(strtolower(trim($entry['query'])), 'update'));

        expect($updateQueries->count())->toBeGreaterThan(1);

        $after = DB::table('runner_users')->get();
        expect($after)->toHaveCount(200);

        foreach ($after as $row) {
            expect($row->name)->not->toStartWith('Seed Name');
        }
    });
}
