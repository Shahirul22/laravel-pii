<?php

namespace {
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraints;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class CcvUser extends Model
    {
        protected $table = 'ccv_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CcvEncryptedUser extends Model
    {
        protected $table = 'ccv_users';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['ssn' => 'encrypted'];
        }
    }

    class CcvPlainUser extends Model
    {
        protected $table = 'ccv_plain_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CcvPlainUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['name' => 'name'];
        }
    }

    class CcvEnumSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['status' => fn () => 'bogus'];
        }
    }

    class CcvNotNullSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => fn () => null];
        }
    }

    class CcvLengthSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => fn () => 'abcdefgh'];
        }
    }

    class CcvEncryptedTooLongSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['ssn' => 'short'];
        }
    }

    class CcvHappyPathSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'status' => fn ($v, $faker) => $faker->randomElement(['active', 'inactive']),
                'code' => fn () => 'C1',
                'nick' => fn () => null,
            ];
        }
    }

    class CcvBootBadEnumSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['status' => 'bogus'];
        }
    }

    /**
     * Test double: same schema-sourced result as the real inspector, except
     * for one column replaced with a stubbed ColumnConstraints — used to pin
     * a length constraint the SQLite test harness itself can never report
     * (design §Driver matrix and schema source: SQLite never enforces a
     * declared length).
     */
    class CcvFakeLengthInspector extends ColumnConstraintInspector
    {
        public function __construct(private DatabaseManager $dbForFake)
        {
            parent::__construct($dbForFake);
        }

        public function constraintsFor(string $table, ?string $connection = null): array
        {
            $map = parent::constraintsFor($table, $connection);
            $map['code'] = new ColumnConstraints('code', 'string', 5, null, false);

            return $map;
        }
    }

    class CcvFakeEncryptedLengthInspector extends ColumnConstraintInspector
    {
        public function __construct(private DatabaseManager $dbForFake)
        {
            parent::__construct($dbForFake);
        }

        public function constraintsFor(string $table, ?string $connection = null): array
        {
            $map = parent::constraintsFor($table, $connection);
            $map['ssn'] = new ColumnConstraints('ssn', 'string', 50, null, false);

            return $map;
        }
    }
}

namespace {

    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    beforeEach(function () {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Schema::create('ccv_users', function ($table) {
            $table->id();
            $table->enum('status', ['active', 'inactive']);
            $table->string('code');
            $table->string('nick')->nullable();
            $table->text('ssn');
        });

        for ($i = 0; $i < 5; $i++) {
            DB::table('ccv_users')->insert([
                'status' => 'active',
                'code' => "C{$i}",
                'nick' => null,
                'ssn' => Crypt::encryptString("000-00-000{$i}"),
            ]);
        }

        Schema::create('ccv_plain_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        for ($i = 0; $i < 3; $i++) {
            DB::table('ccv_plain_users')->insert(['name' => "Seed {$i}"]);
        }
    });

    it('rolls back the chunk with a named error for a per-row enum violation (AC-10)', function () {
        config()->set('pii.sanitizers', [CcvUser::class => CcvEnumSanitizer::class]);
        config()->set('pii.models', [CcvUser::class]);

        $before = DB::table('ccv_users')->orderBy('id')->get()->all();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(ConstraintViolationException::class);
        expect($chunk->failureMessage)->toContain(CcvUser::class);
        expect($chunk->failureMessage)->toContain('status');
        expect($chunk->failureMessage)->toContain('ccv_users');
        expect($chunk->failureMessage)->toContain('allowed');
        expect($chunk->failureMessage)->not->toContain('bogus');
        expect($chunk->failureMessage)->not->toContain('is withheld because it can contain row values');

        $after = DB::table('ccv_users')->orderBy('id')->get()->all();
        expect($after)->toEqual($before);
    });

    it('rolls back the chunk with a named error for a per-row NOT NULL violation (AC-10)', function () {
        config()->set('pii.sanitizers', [CcvUser::class => CcvNotNullSanitizer::class]);
        config()->set('pii.models', [CcvUser::class]);

        $before = DB::table('ccv_users')->orderBy('id')->get()->all();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(ConstraintViolationException::class);
        expect($chunk->failureMessage)->toContain('NOT NULL');

        $after = DB::table('ccv_users')->orderBy('id')->get()->all();
        expect($after)->toEqual($before);
    });

    it('rolls back the chunk with a named error for a per-row length violation (AC-10)', function () {
        app()->bind(ColumnConstraintInspector::class, fn ($app) => new CcvFakeLengthInspector($app->make('db')));

        config()->set('pii.sanitizers', [CcvUser::class => CcvLengthSanitizer::class]);
        config()->set('pii.models', [CcvUser::class]);

        $before = DB::table('ccv_users')->orderBy('id')->get()->all();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(ConstraintViolationException::class);
        expect($chunk->failureMessage)->toContain('8');
        expect($chunk->failureMessage)->toContain('5');
        expect($chunk->failureMessage)->toContain('length');
        expect($chunk->failureMessage)->not->toContain('abcdefgh');

        $after = DB::table('ccv_users')->orderBy('id')->get()->all();
        expect($after)->toEqual($before);
    });

    it('validates the post-encode ciphertext length, not the pre-encode plaintext length (Pipeline order)', function () {
        app()->bind(ColumnConstraintInspector::class, fn ($app) => new CcvFakeEncryptedLengthInspector($app->make('db')));

        config()->set('pii.sanitizers', [CcvEncryptedUser::class => CcvEncryptedTooLongSanitizer::class]);
        config()->set('pii.models', [CcvEncryptedUser::class]);

        // Not rejected at boot: 'ssn' is cast-bearing, so boot validation
        // (§R6.3) exempts it regardless of its static value.
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(ConstraintViolationException::class);
        expect($chunk->failureMessage)->toContain('length');
    });

    it('reports the same per-row violation during a dry run', function () {
        config()->set('pii.sanitizers', [CcvUser::class => CcvEnumSanitizer::class]);
        config()->set('pii.models', [CcvUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(ConstraintViolationException::class);
    });

    it('throws at boot, before any row is read or written, for a static declaration violation (R6.3)', function () {
        config()->set('pii.sanitizers', [CcvUser::class => CcvBootBadEnumSanitizer::class]);
        config()->set('pii.models', [CcvUser::class]);

        $before = DB::table('ccv_users')->orderBy('id')->get()->all();

        DB::flushQueryLog();
        DB::enableQueryLog();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow(ConstraintViolationException::class);

        foreach (DB::getQueryLog() as $query) {
            $sql = strtolower(trim($query['query'] ?? $query['sql'] ?? ''));
            expect($sql)->not->toStartWith('select * from "ccv_users"');
            expect(str_starts_with($sql, 'update') && str_contains($sql, 'ccv_users'))->toBeFalse();
            expect(str_starts_with($sql, 'insert') && str_contains($sql, 'ccv_users'))->toBeFalse();
        }

        $after = DB::table('ccv_users')->orderBy('id')->get()->all();
        expect($after)->toEqual($before);

        expect(fn () => Sanitizer::for(CcvUser::class))->toThrow(ConstraintViolationException::class);
    });

    it('fails at boot before any model in the run is touched, when a later model has the violation (R6.3)', function () {
        config()->set('pii.sanitizers', [
            CcvPlainUser::class => CcvPlainUserSanitizer::class,
            CcvUser::class => CcvBootBadEnumSanitizer::class,
        ]);
        config()->set('pii.models', [CcvPlainUser::class, CcvUser::class]);

        $beforePlain = DB::table('ccv_plain_users')->orderBy('id')->get()->all();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow(ConstraintViolationException::class);

        $afterPlain = DB::table('ccv_plain_users')->orderBy('id')->get()->all();
        expect($afterPlain)->toEqual($beforePlain);
    });

    it('sanitizes cleanly end to end with no constraint violation (R9 no-regression)', function () {
        config()->set('pii.sanitizers', [CcvUser::class => CcvHappyPathSanitizer::class]);
        config()->set('pii.models', [CcvUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->chunks[0]->status)->toBe(ChunkStatus::Completed);

        foreach (CcvUser::all() as $row) {
            expect($row->nick)->toBeNull();
        }
    });
}
