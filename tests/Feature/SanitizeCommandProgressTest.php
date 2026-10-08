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

    class CmdProgressNaturalUser extends Model
    {
        protected $table = 'cmd_progress_natural_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CmdProgressNaturalUserSanitizer extends Sanitizer
    {
        public static int $seen = 0;

        public function fields(): array
        {
            return [
                'name' => function ($value, $faker, $row) {
                    self::$seen++;

                    if (self::$seen === 3) {
                        throw new RuntimeException('boom');
                    }

                    return $faker->name();
                },
            ];
        }
    }

    class CmdProgressStringKeyUser extends Model
    {
        protected $table = 'cmd_progress_string_key_users';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;

        protected $primaryKey = 'code';

        protected $keyType = 'string';
    }

    class CmdProgressCompositeKeyUser extends Model
    {
        protected $table = 'cmd_progress_composite_key_users';

        protected $guarded = [];

        public $timestamps = false;
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
    use Symfony\Component\Console\Output\BufferedOutput;

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

    /**
     * Run the command and return its real console output. The mocked
     * output of `$this->artisan()` does not see every line, so assertions
     * about what is printed read a buffer instead.
     *
     * @param  array<string, mixed>  $options
     */
    function cmdProgressRun(array $options, int $expectedExit): string
    {
        $buffer = new BufferedOutput;

        expect(Artisan::call('pii:sanitize', $options, $buffer))->toBe($expectedExit);

        return $buffer->fetch();
    }

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
            ->expectsOutputToContain(sprintf('%s: the chunk failed with an unexpected exception. Its message is withheld because it can contain row values.', RuntimeException::class))
            ->doesntExpectOutputToContain('application logs')
            ->expectsOutputToContain('Chunks not attempted')
            ->expectsOutputToContain('1 of 3')
            ->expectsOutputToContain('failed')
            ->run();

        $output = Artisan::output();
        expect($output)->not->toContain('boom');
    });

    it('names the exception class once in the failure line', function () {
        config()->set('pii.models', [CmdProgressFailingUser::class]);

        $output = cmdProgressRun(['--chunk' => 10], 1);

        expect($output)->toContain('RuntimeException: the chunk failed');
        expect($output)->not->toContain('RuntimeException: RuntimeException');
    });

    it('shows the key range of a failed chunk for a numeric identity', function () {
        config()->set('pii.models', [CmdProgressFailingUser::class]);

        expect(cmdProgressRun(['--chunk' => 10], 1))->toContain('Chunk #2 failed (keys 11–20)');
    });

    it('withholds the identity of a failed chunk when it is a natural key', function () {
        CmdProgressNaturalUserSanitizer::$seen = 0;

        Schema::create('cmd_progress_natural_users', function ($table) {
            $table->string('email')->unique();
            $table->string('name');
        });

        for ($i = 0; $i < 6; $i++) {
            DB::table('cmd_progress_natural_users')->insert(['email' => "natural-{$i}@example.com", 'name' => "Seed {$i}"]);
        }

        config()->set('pii.sanitizers', [CmdProgressNaturalUser::class => CmdProgressNaturalUserSanitizer::class]);
        config()->set('pii.models', [CmdProgressNaturalUser::class]);

        $output = cmdProgressRun(['--chunk' => 2], 1);

        expect($output)->toContain('Chunk #2 failed (identity values withheld)');
        expect($output)->not->toContain('natural-');
        expect($output)->not->toContain('@example.com');
    });

    /**
     * Fails chunk #2 (rows 3 and 4) of a run over $codes with chunk size 2
     * and returns the command output.
     *
     * @param  list<string>  $codes
     */
    function cmdProgressFailStringKeys(array $codes): string
    {
        CmdProgressNaturalUserSanitizer::$seen = 0;

        Schema::create('cmd_progress_string_key_users', function ($table) {
            $table->string('code')->primary();
            $table->string('name');
        });

        foreach ($codes as $code) {
            DB::table('cmd_progress_string_key_users')->insert(['code' => $code, 'name' => 'Seed']);
        }

        config()->set('pii.sanitizers', [CmdProgressStringKeyUser::class => CmdProgressNaturalUserSanitizer::class]);
        config()->set('pii.models', [CmdProgressStringKeyUser::class]);

        return cmdProgressRun(['--chunk' => 2], 1);
    }

    // BUG-15 (round 2): the failure line prints a chunk's key range only
    // for integer, UUID and ULID identities, matched as the whole value.
    it('prints the key range of a failed chunk for a UUID identity', function () {
        $codes = array_map(fn (int $i): string => sprintf('0191a2b3-4c5d-6e7f-8a9b-0c1d2e3f4a5%d', $i), range(0, 5));

        expect(cmdProgressFailStringKeys($codes))->toContain('Chunk #2 failed (keys 0191a2b3-4c5d-6e7f-8a9b-0c1d2e3f4a52–0191a2b3-4c5d-6e7f-8a9b-0c1d2e3f4a53)');
    });

    it('prints the key range of a failed chunk for a ULID identity', function () {
        $codes = array_map(fn (int $i): string => sprintf('01J9ZQ4V5B6C7D8E9F0GHJKMN%d', $i), range(0, 5));

        expect(cmdProgressFailStringKeys($codes))->toContain('Chunk #2 failed (keys 01J9ZQ4V5B6C7D8E9F0GHJKMN2–01J9ZQ4V5B6C7D8E9F0GHJKMN3)');
    });

    it('withholds an identity that only contains a UUID', function (string $format) {
        $codes = array_map(fn (int $i): string => sprintf($format, $i), range(0, 5));

        $output = cmdProgressFailStringKeys($codes);

        expect($output)->toContain('Chunk #2 failed (identity values withheld)');
        expect($output)->not->toContain('corp.example');
        expect($output)->not->toContain('0191a2b3');
    })->with([
        'UUID then an email domain' => ['0191a2b3-4c5d-6e7f-8a9b-0c1d2e3f4a5%d@corp.example'],
        'a name then a UUID' => ['corp.example-0191a2b3-4c5d-6e7f-8a9b-0c1d2e3f4a5%d'],
        'UUID then a newline' => ["0191a2b3-4c5d-6e7f-8a9b-0c1d2e3f4a5%d\ncorp.example"],
    ]);

    it('withholds a composite identity when one member is a natural key', function () {
        CmdProgressNaturalUserSanitizer::$seen = 0;

        Schema::create('cmd_progress_composite_key_users', function ($table) {
            $table->integer('org_id');
            $table->string('email');
            $table->string('name');
            $table->primary(['org_id', 'email']);
        });

        for ($i = 0; $i < 6; $i++) {
            DB::table('cmd_progress_composite_key_users')->insert(['org_id' => 1, 'email' => "person-{$i}@corp.example", 'name' => 'Seed']);
        }

        config()->set('pii.sanitizers', [CmdProgressCompositeKeyUser::class => CmdProgressNaturalUserSanitizer::class]);
        config()->set('pii.models', [CmdProgressCompositeKeyUser::class]);

        $output = cmdProgressRun(['--chunk' => 2], 1);

        expect($output)->toContain('Chunk #2 failed (identity values withheld)');
        expect($output)->not->toContain('person-');
        expect($output)->not->toContain('corp.example');
    });

    it('prints a composite identity whose members are all integers', function () {
        CmdProgressNaturalUserSanitizer::$seen = 0;

        Schema::create('cmd_progress_composite_key_users', function ($table) {
            $table->integer('org_id');
            $table->integer('member_id');
            $table->string('name');
            $table->primary(['org_id', 'member_id']);
        });

        for ($i = 1; $i <= 6; $i++) {
            DB::table('cmd_progress_composite_key_users')->insert(['org_id' => 1, 'member_id' => $i, 'name' => 'Seed']);
        }

        config()->set('pii.sanitizers', [CmdProgressCompositeKeyUser::class => CmdProgressNaturalUserSanitizer::class]);
        config()->set('pii.models', [CmdProgressCompositeKeyUser::class]);

        expect(cmdProgressRun(['--chunk' => 2], 1))->toContain('Chunk #2 failed (keys org_id=1, member_id=3–org_id=1, member_id=4)');
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
