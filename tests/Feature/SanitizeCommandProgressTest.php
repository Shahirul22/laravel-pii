<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class CmdProgressUser extends Model
    {
        protected $table = 'cmd_progress_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CmdProgressUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
                'email' => 'safeEmail',
            ];
        }
    }

    class CmdProgressFailingUser extends Model
    {
        protected $table = 'cmd_progress_failing_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CmdProgressFailingUserSanitizer extends Sanitizer
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

    class CmdProgressUnsanitizedUser extends Model
    {
        protected $table = 'cmd_progress_unsanitized_users';

        protected $guarded = [];

        public $timestamps = false;
    }
}

namespace {

    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\ProgressEvent;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        CmdProgressFailingUserSanitizer::$seen = 0;

        Schema::create('cmd_progress_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });

        for ($i = 0; $i < 25; $i++) {
            DB::table('cmd_progress_users')->insert([
                'name' => "Seed Name {$i}",
                'email' => "seed-{$i}@example.com",
            ]);
        }

        Schema::create('cmd_progress_failing_users', function ($table) {
            $table->id();
            $table->string('email');
        });

        for ($i = 0; $i < 25; $i++) {
            DB::table('cmd_progress_failing_users')->insert([
                'email' => "seed-{$i}@example.com",
            ]);
        }

        config()->set('pii.sanitizers', [
            CmdProgressUser::class => CmdProgressUserSanitizer::class,
            CmdProgressFailingUser::class => CmdProgressFailingUserSanitizer::class,
        ]);
        config()->set('pii.models', [CmdProgressUser::class]);
    });

    afterEach(function () {
        app()->detectEnvironment(fn () => 'testing');
    });

    it('emits progress output across a multi-chunk run', function () {
        $this->artisan('pii:sanitize', ['--chunk' => 5])
            ->assertExitCode(0)
            ->expectsOutputToContain('Sanitizing 1 model(s).')
            ->expectsOutputToContain('5/5 chunks')
            ->expectsOutputToContain(CmdProgressUser::class)
            ->expectsOutputToContain('completed successfully')
            ->run();
    });

    it('renders the dry-run summary and exits 0', function () {
        $this->artisan('pii:sanitize', ['--dry-run' => true, '--chunk' => 5])
            ->assertExitCode(0)
            ->expectsOutputToContain('nothing was written to the database')
            ->expectsOutputToContain('would be sanitized')
            ->expectsOutputToContain('email')
            ->expectsOutputToContain('name')
            ->expectsOutputToContain('Dry run')
            ->run();
    });

    it('renders the failure summary with a chunk breakdown and exits 1', function () {
        config()->set('pii.models', [CmdProgressFailingUser::class]);

        $this->artisan('pii:sanitize', ['--chunk' => 10])
            ->assertExitCode(1)
            ->expectsOutputToContain('Chunk #2')
            ->expectsOutputToContain(sprintf('%s: database exception during chunk write', RuntimeException::class))
            ->expectsOutputToContain('Chunks not attempted')
            ->expectsOutputToContain('1 of 3')
            ->expectsOutputToContain('failed')
            ->run();

        $output = Artisan::output();
        expect($output)->not->toContain('boom');
    });

    it('names an unattempted model explicitly in the failure summary', function () {
        config()->set('pii.models', [CmdProgressFailingUser::class, CmdProgressUser::class]);

        $this->artisan('pii:sanitize', ['--chunk' => 10])
            ->assertExitCode(1)
            ->expectsOutputToContain(CmdProgressUser::class)
            ->expectsOutputToContain('not attempted')
            ->run();
    });

    it('renders a skipped model without drawing a progress bar', function () {
        Schema::create('cmd_progress_unsanitized_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::table('cmd_progress_unsanitized_users')->insert(['name' => 'seed']);

        config()->set('pii.models', [CmdProgressUnsanitizedUser::class]);

        $this->artisan('pii:sanitize', ['--chunk' => 10])
            ->assertExitCode(0)
            ->expectsOutputToContain('skipped')
            ->run();
    });

    it('invokes the progress hook for model-start and each chunk', function () {
        $events = [];

        app(SanitizationRunner::class)->run(new RunOptions(
            chunkSize: 5,
            onProgress: function (ProgressEvent $e) use (&$events) {
                $events[] = $e;
            },
            models: [CmdProgressUser::class],
        ));

        expect($events)->toHaveCount(6);

        expect($events[0]->chunk)->toBeNull();
        expect($events[0]->expectedChunks)->toBe(5);
        expect($events[0]->rowsProcessed)->toBe(0);
        expect($events[0]->modelClass)->toBe(CmdProgressUser::class);
        expect($events[0]->table)->toBe('cmd_progress_users');

        for ($i = 1; $i <= 5; $i++) {
            expect($events[$i]->chunk)->not->toBeNull();
            expect($events[$i]->chunk->index)->toBe($i);
        }
    });

    it('fires a chunk-complete event for a rolled-back chunk', function () {
        $events = [];

        app(SanitizationRunner::class)->run(new RunOptions(
            chunkSize: 10,
            onProgress: function (ProgressEvent $e) use (&$events) {
                $events[] = $e;
            },
            models: [CmdProgressFailingUser::class],
        ));

        $last = end($events);

        expect($last->chunk)->not->toBeNull();
        expect($last->chunk->status)->toBe(ChunkStatus::RolledBack);
    });
}
