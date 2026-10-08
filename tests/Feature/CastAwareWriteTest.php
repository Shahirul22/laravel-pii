<?php

namespace {
    use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class CastWriteMultiColumnCast implements CastsAttributes
    {
        public function get($model, string $key, $value, array $attributes): mixed
        {
            return $value;
        }

        public function set($model, string $key, $value, array $attributes): mixed
        {
            return [$key => $value, 'name' => 'x'];
        }
    }

    class CastWriteThrowingCast implements CastsAttributes
    {
        public function get($model, string $key, $value, array $attributes): mixed
        {
            return $value;
        }

        public function set($model, string $key, $value, array $attributes): mixed
        {
            throw new InvalidArgumentException('cannot encode '.$value);
        }
    }

    class CastWriteUser extends Model
    {
        protected $table = 'cast_write_users';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return [
                'ssn' => 'encrypted',
            ];
        }
    }

    class CastWriteUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
                'ssn' => fn () => '999-00-1111',
            ];
        }
    }

    class CastWritePlainUser extends Model
    {
        protected $table = 'cast_write_plain_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class CastWritePlainUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'name',
                'email' => 'safeEmail',
            ];
        }
    }

    class CastWriteMultiUser extends Model
    {
        protected $table = 'cast_write_users';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return [
                'ssn' => CastWriteMultiColumnCast::class,
            ];
        }
    }

    class CastWriteMultiUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'ssn' => fn () => '999-00-1111',
            ];
        }
    }

    class CastWriteThrowingUser extends Model
    {
        protected $table = 'cast_write_users';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return [
                'ssn' => CastWriteThrowingCast::class,
            ];
        }
    }

    class CastWriteThrowingUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'ssn' => fn () => 'SECRET-PLAINTEXT',
            ];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsupportedCastException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Schema::create('cast_write_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('ssn');
        });

        for ($i = 0; $i < 5; $i++) {
            DB::table('cast_write_users')->insert([
                'name' => "Seed {$i}",
                'ssn' => Crypt::encryptString("000-00-000{$i}"),
            ]);
        }

        Schema::create('cast_write_plain_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });

        for ($i = 0; $i < 5; $i++) {
            DB::table('cast_write_plain_users')->insert([
                'name' => "Seed {$i}",
                'email' => "seed-{$i}@example.com",
            ]);
        }
    });

    it('round-trips an encrypted-cast column through a sanitize run (AC-8)', function () {
        config()->set('pii.sanitizers', [
            CastWriteUser::class => CastWriteUserSanitizer::class,
        ]);
        config()->set('pii.models', [CastWriteUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->chunks[0]->status)->toBe(ChunkStatus::Completed);

        foreach (CastWriteUser::all() as $row) {
            expect($row->ssn)->toBe('999-00-1111');
            expect($row->name)->not->toStartWith('Seed');
        }

        $rawSsns = DB::table('cast_write_users')->pluck('ssn');

        foreach ($rawSsns as $raw) {
            expect($raw)->not->toBe('999-00-1111');
            expect(Crypt::decryptString($raw))->toBe('999-00-1111');
        }
    });

    it('keeps the changed-count logical, not raw-ciphertext-based (AC-8)', function () {
        config()->set('pii.sanitizers', [
            CastWriteUser::class => CastWriteUserSanitizer::class,
        ]);
        config()->set('pii.models', [CastWriteUser::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->models[0]->columnCounts['ssn'])->toBe(5);
    });

    it('refuses a multi-column class cast and rolls back the chunk without any write (AC-8)', function () {
        config()->set('pii.sanitizers', [
            CastWriteMultiUser::class => CastWriteMultiUserSanitizer::class,
        ]);
        config()->set('pii.models', [CastWriteMultiUser::class]);

        $before = DB::table('cast_write_users')->orderBy('id')->get()->all();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(UnsupportedCastException::class);
        expect($chunk->failureMessage)->toContain('ssn');
        expect($chunk->failureMessage)->toContain(CastWriteMultiUser::class);
        expect($chunk->failureMessage)->toContain('cast_write_users');
        expect($chunk->failureMessage)->toContain('name');
        expect($chunk->failureMessage)->not->toContain('is withheld because it can contain row values');

        $after = DB::table('cast_write_users')->orderBy('id')->get()->all();

        expect($after)->toEqual($before);
    });

    it('refuses an encode failure without leaking the value, and rolls back (AC-8)', function () {
        config()->set('pii.sanitizers', [
            CastWriteThrowingUser::class => CastWriteThrowingUserSanitizer::class,
        ]);
        config()->set('pii.models', [CastWriteThrowingUser::class]);

        $before = DB::table('cast_write_users')->orderBy('id')->get()->all();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(UnsupportedCastException::class);
        expect($chunk->failureMessage)->toContain('ssn');
        expect($chunk->failureMessage)->toContain(InvalidArgumentException::class);
        expect($chunk->failureMessage)->not->toContain('SECRET-PLAINTEXT');

        $after = DB::table('cast_write_users')->orderBy('id')->get()->all();

        expect($after)->toEqual($before);
    });

    it('still encodes and refuses a bad cast during a dry run (AC-8)', function () {
        config()->set('pii.sanitizers', [
            CastWriteMultiUser::class => CastWriteMultiUserSanitizer::class,
        ]);
        config()->set('pii.models', [CastWriteMultiUser::class]);

        $ctor = new ReflectionMethod(RunOptions::class, '__construct');
        $paramNames = array_map(fn (ReflectionParameter $p) => $p->getName(), $ctor->getParameters());
        expect($paramNames)->toContain('dryRun');

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10, dryRun: true));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(UnsupportedCastException::class);
    });

    it('keeps v1\'s exact single batched UPDATE statement for a non-cast-only run (AC-9)', function () {
        config()->set('pii.sanitizers', [
            CastWritePlainUser::class => CastWritePlainUserSanitizer::class,
        ]);
        config()->set('pii.models', [CastWritePlainUser::class]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with(strtolower(trim($query->sql)), 'update')) {
                $queries[] = $query;
            }
        });

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($queries)->toHaveCount(1);

        $ids = DB::table('cast_write_plain_users')->orderBy('id')->pluck('id')->all();
        $whenPairs = str_repeat('WHEN ? THEN ? ', count($ids));
        $whenPairs = trim($whenPairs);

        $expectedSql = sprintf(
            'UPDATE "cast_write_plain_users" SET "name" = CASE "id" %s END, "email" = CASE "id" %s END WHERE "id" IN (%s)',
            $whenPairs,
            $whenPairs,
            implode(', ', array_fill(0, count($ids), '?'))
        );

        expect($queries[0]->sql)->toBe($expectedSql);
        expect(count($queries[0]->bindings))->toBe(5 * 2 * 2 + 5);
    });

    it('keeps a mixed cast/plain table on the bulk path with no per-row UPDATE fallback (AC-9)', function () {
        config()->set('pii.sanitizers', [
            CastWriteUser::class => CastWriteUserSanitizer::class,
        ]);
        config()->set('pii.models', [CastWriteUser::class]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with(strtolower(trim($query->sql)), 'update')) {
                $queries[] = $query;
            }
        });

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($queries)->toHaveCount(1);
        expect($queries[0]->sql)->toContain('CASE "id" WHEN ? THEN ?');

        foreach ($queries[0]->bindings as $binding) {
            expect($binding)->not->toBe('999-00-1111');
        }
    });
}
