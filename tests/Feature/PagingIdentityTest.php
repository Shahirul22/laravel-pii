<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class PhMembership extends Model
    {
        protected $table = 'ph_memberships';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhMembershipSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PhMembershipBadSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail', 'member_id' => fn () => 999];
        }
    }

    class PhToken extends Model
    {
        protected $table = 'ph_tokens';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;

        protected $keyType = 'string';
    }

    class PhTokenSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PhLog extends Model
    {
        protected $table = 'ph_logs';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhLogSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail', 'bio' => null];
        }
    }

    class PhPerson extends Model
    {
        protected $table = 'ph_people';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhPersonSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PhPersonStabilitySanitizer extends Sanitizer
    {
        /** @var list<string> */
        public static array $seen = [];

        private static int $counter = 0;

        public function fields(): array
        {
            return [
                'email' => function ($value, $faker, $row) {
                    self::$seen[] = $row->getAttributes()['first'].'|'.$row->getAttributes()['last'];
                    self::$counter++;

                    return 'aaa-'.(1000 - self::$counter).'@x.test';
                },
            ];
        }
    }

    class PhGoodUser extends Model
    {
        protected $table = 'ph_good_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhGoodUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PhDupe extends Model
    {
        protected $table = 'ph_dupes';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhDupeSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['a' => 'safeEmail'];
        }
    }

    class PhDeclared extends Model
    {
        protected $table = 'ph_declared';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhDeclaredSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }

        public function pagingKey(): array
        {
            return ['ref'];
        }
    }

    class PhMembershipEncryptedModel extends Model
    {
        protected $table = 'ph_memberships';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['email' => 'encrypted'];
        }
    }

    class PhMembershipEncryptedSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => fn () => 'plain-email@x.test'];
        }
    }

    class PhMembershipConstrained extends Model
    {
        protected $table = 'ph_memberships_constrained';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhMembershipConstrainedSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['status' => fn () => 'zzz'];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Str;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\ConstraintViolationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        Schema::create('ph_memberships', function ($table) {
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('member_id');
            $table->string('email');
            $table->primary(['org_id', 'member_id']);
        });

        PhPersonStabilitySanitizer::$seen = [];
    });

    it('sanitizes a composite-PK table, paging and writing by keyset (R7.1)', function () {
        foreach (range(1, 3) as $org) {
            foreach (range(1, 4) as $member) {
                DB::table('ph_memberships')->insert([
                    'org_id' => $org,
                    'member_id' => $member,
                    'email' => "org{$org}-member{$member}@x.test",
                ]);
            }
        }

        config()->set('pii.sanitizers', [PhMembership::class => PhMembershipSanitizer::class]);
        config()->set('pii.models', [PhMembership::class]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5));

        expect($report->failed())->toBeFalse();

        $model = $report->models[0];
        expect($model->chunks)->toHaveCount(3);
        expect($model->rowsSanitized())->toBe(12);

        $rows = DB::table('ph_memberships')->orderBy('org_id')->orderBy('member_id')->get();
        expect($rows)->toHaveCount(12);

        foreach ($rows as $row) {
            expect($row->email)->toContain('@');
            expect($row->email)->not->toStartWith('org'.$row->org_id.'-member'.$row->member_id.'@');
        }

        expect($model->chunks[0]->firstKey)->toBe(['org_id' => 1, 'member_id' => 1]);
        expect($model->chunks[0]->lastKey)->toBe(['org_id' => 2, 'member_id' => 1]);
        expect($model->chunks[1]->firstKey)->toBe(['org_id' => 2, 'member_id' => 2]);

        $updateQueries = array_values(array_filter($queries, fn ($sql) => str_starts_with(strtolower(trim($sql)), 'update')));
        expect($updateQueries)->not->toBeEmpty();
        expect($updateQueries[0])->toContain('CASE WHEN "org_id" = ? AND "member_id" = ? THEN ?');
        expect($updateQueries[0])->toContain('WHERE ("org_id" = ? AND "member_id" = ?) OR (');

        $selectQueries = array_values(array_filter($queries, fn ($sql) => str_starts_with(strtolower(trim($sql)), 'select')));
        foreach ($selectQueries as $sql) {
            expect(strtolower($sql))->not->toContain('offset');
        }
    });

    it('protects every composite PK column, not only the first', function () {
        DB::table('ph_memberships')->insert(['org_id' => 1, 'member_id' => 1, 'email' => 'a@x.test']);

        config()->set('pii.sanitizers', [PhMembership::class => PhMembershipBadSanitizer::class]);
        config()->set('pii.models', [PhMembership::class]);

        $before = DB::table('ph_memberships')->get();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5)))
            ->toThrow(UnsafeColumnException::class);

        $after = DB::table('ph_memberships')->get();
        expect($after)->toEqual($before);
    });

    it('sub-batches a composite-key chunk within the parameter budget', function () {
        foreach (range(1, 10) as $org) {
            foreach (range(1, 20) as $member) {
                DB::table('ph_memberships')->insert([
                    'org_id' => $org,
                    'member_id' => $member,
                    'email' => "org{$org}-member{$member}@x.test",
                ]);
            }
        }

        config()->set('pii.sanitizers', [PhMembership::class => PhMembershipSanitizer::class]);
        config()->set('pii.models', [PhMembership::class]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 200));

        expect($report->models[0]->rowsSanitized())->toBe(200);

        $updateQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_starts_with(strtolower(trim($entry['query'])), 'update'));

        expect($updateQueries->count())->toBe(3);
    });

    it('sanitizes a UUID/string-PK table without int-coercing an all-digit id (R7.2)', function () {
        Schema::create('ph_tokens', function ($table) {
            $table->string('id')->primary();
            $table->string('email');
        });

        $ids = [(string) Str::uuid(), (string) Str::uuid(), '12', '0012', 'abc', 'def', 'ghi'];

        foreach ($ids as $i => $id) {
            DB::table('ph_tokens')->insert(['id' => $id, 'email' => "seed{$i}@x.test"]);
        }

        config()->set('pii.sanitizers', [PhToken::class => PhTokenSanitizer::class]);
        config()->set('pii.models', [PhToken::class]);

        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (str_starts_with(strtolower(trim($query->sql)), 'update')) {
                $bindings = [...$bindings, ...$query->bindings];
            }
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 3));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(7);
        expect($report->models[0]->chunks[0]->firstKey)->toBeString();

        $after = DB::table('ph_tokens')->pluck('id')->all();
        sort($after, SORT_STRING);
        $expectedIds = $ids;
        sort($expectedIds, SORT_STRING);
        expect($after)->toBe($expectedIds);

        $hasStringTwelve = false;
        foreach ($bindings as $binding) {
            if ($binding === '12') {
                $hasStringTwelve = true;
            }
            expect($binding)->not->toBe(12);
        }
        expect($hasStringTwelve)->toBeTrue();
    });

    it('sanitizes a keyless table via the single-column fallback (R7.3)', function () {
        Schema::create('ph_logs', function ($table) {
            $table->string('code');
            $table->string('email');
            $table->text('bio')->nullable();
        });

        for ($i = 0; $i < 10; $i++) {
            DB::table('ph_logs')->insert(['code' => "code-{$i}", 'email' => "e{$i}@x.test", 'bio' => null]);
        }

        config()->set('pii.sanitizers', [PhLog::class => PhLogSanitizer::class]);
        config()->set('pii.models', [PhLog::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 3));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(10);
        expect($report->models[0]->chunks)->toHaveCount(4);

        $codes = DB::table('ph_logs')->pluck('code')->all();
        expect($codes)->toHaveCount(10);
        expect(array_unique($codes))->toHaveCount(10);
    });

    it('sanitizes a keyless table via the multi-column fallback (R7.3)', function () {
        Schema::create('ph_people', function ($table) {
            $table->string('first');
            $table->string('last');
            $table->string('email');
        });

        $firsts = ['a', 'a', 'a', 'b', 'b', 'b', 'c', 'c', 'c'];

        foreach ($firsts as $i => $first) {
            DB::table('ph_people')->insert(['first' => $first, 'last' => "l{$i}", 'email' => "e{$i}@x.test"]);
        }

        config()->set('pii.sanitizers', [PhPerson::class => PhPersonSanitizer::class]);
        config()->set('pii.models', [PhPerson::class]);

        $selectQueries = [];
        DB::listen(function ($query) use (&$selectQueries) {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $selectQueries[] = $query->sql;
            }
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 4));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(9);

        foreach ($selectQueries as $sql) {
            expect(strtolower($sql))->not->toContain('offset');
        }
    });

    it('is stable under in-place mutation of a naive paged-on value (stability AC)', function () {
        Schema::create('ph_people', function ($table) {
            $table->string('first');
            $table->string('last');
            $table->string('email');
        });

        $firsts = ['a', 'a', 'a', 'b', 'b', 'b', 'c', 'c', 'c'];
        $identities = [];

        foreach ($firsts as $i => $first) {
            $last = sprintf('%02d', 9 - $i);
            DB::table('ph_people')->insert(['first' => $first, 'last' => $last, 'email' => sprintf('%09d@x.test', $i)]);
            $identities[] = $first.'|'.$last;
        }

        config()->set('pii.sanitizers', [PhPerson::class => PhPersonStabilitySanitizer::class]);
        config()->set('pii.models', [PhPerson::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();

        $seen = PhPersonStabilitySanitizer::$seen;

        expect($seen)->toHaveCount(9);
        expect(array_unique($seen))->toHaveCount(9);

        $sortedSeen = $seen;
        sort($sortedSeen);
        $sortedIdentities = $identities;
        sort($sortedIdentities);
        expect($sortedSeen)->toBe($sortedIdentities);

        $emails = DB::table('ph_people')->pluck('email')->all();
        foreach ($emails as $email) {
            expect($email)->toStartWith('aaa-');
        }

        expect($report->models[0]->rowsScanned)->toBe(9);
        expect($report->models[0]->rowsSanitized())->toBe(9);
    });

    it('fails fast at boot for a fully-duplicated keyless table, before any earlier target is touched', function () {
        Schema::create('ph_good_users', function ($table) {
            $table->id();
            $table->string('email');
        });

        Schema::create('ph_dupes', function ($table) {
            $table->string('a');
            $table->string('b');
        });

        DB::table('ph_good_users')->insert(['email' => 'a@x.test']);
        DB::table('ph_dupes')->insert(['a' => 'x', 'b' => 'y']);
        DB::table('ph_dupes')->insert(['a' => 'x', 'b' => 'y']);

        config()->set('pii.sanitizers', [
            PhGoodUser::class => PhGoodUserSanitizer::class,
            PhDupe::class => PhDupeSanitizer::class,
        ]);
        config()->set('pii.models', [PhGoodUser::class, PhDupe::class]);

        $before = DB::table('ph_good_users')->get();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5)))
            ->toThrow(UnpageableTableException::class);

        $after = DB::table('ph_good_users')->get();
        expect($after)->toEqual($before);
    });

    it('honors a declared pagingKey() end to end', function () {
        Schema::create('ph_declared', function ($table) {
            $table->string('ref');
            $table->string('code');
            $table->string('email');
        });

        DB::table('ph_declared')->insert(['ref' => 'r1', 'code' => 'dup', 'email' => 'a@x.test']);
        DB::table('ph_declared')->insert(['ref' => 'r2', 'code' => 'dup', 'email' => 'b@x.test']);

        config()->set('pii.sanitizers', [PhDeclared::class => PhDeclaredSanitizer::class]);
        config()->set('pii.models', [PhDeclared::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(2);
    });

    it('still routes a composite-key run through the R5 cast-aware write pipeline', function () {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        DB::table('ph_memberships')->insert(['org_id' => 1, 'member_id' => 1, 'email' => Crypt::encryptString('seed@x.test')]);

        config()->set('pii.sanitizers', [PhMembershipEncryptedModel::class => PhMembershipEncryptedSanitizer::class]);
        config()->set('pii.models', [PhMembershipEncryptedModel::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5));

        expect($report->failed())->toBeFalse();

        foreach (PhMembershipEncryptedModel::all() as $row) {
            expect($row->email)->toBe('plain-email@x.test');
        }

        $raw = DB::table('ph_memberships')->value('email');
        expect($raw)->not->toBe('plain-email@x.test');
    });

    it('still routes a composite-key run through the R6 constraint-validation pipeline', function () {
        Schema::create('ph_memberships_constrained', function ($table) {
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('member_id');
            $table->enum('status', ['a', 'b']);
            $table->primary(['org_id', 'member_id']);
        });

        DB::table('ph_memberships_constrained')->insert(['org_id' => 1, 'member_id' => 1, 'status' => 'a']);

        config()->set('pii.sanitizers', [PhMembershipConstrained::class => PhMembershipConstrainedSanitizer::class]);
        config()->set('pii.models', [PhMembershipConstrained::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5));

        $chunk = $report->models[0]->chunks[0];
        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(ConstraintViolationException::class);

        $row = DB::table('ph_memberships_constrained')->first();
        expect($row->status)->toBe('a');
    });
}

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class PhNullableCode extends Model
    {
        protected $table = 'ph_nullable_codes';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhNullableCodeSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => fn ($value) => 'clean-'.$value];
        }
    }

    it('sanitizes every row of a table whose only unique index is on a nullable column holding NULLs (BUG-28)', function () {
        Schema::create('ph_nullable_codes', function ($table) {
            $table->string('code')->nullable()->unique();
            $table->string('ref');
            $table->string('email');
        });

        // One NULL only: the uniqueness probe groups NULLs together, so two
        // of them would hide a missing NOT NULL check.
        DB::table('ph_nullable_codes')->insert(['code' => null, 'ref' => 'r1', 'email' => 'a@real.test']);
        DB::table('ph_nullable_codes')->insert(['code' => 'b', 'ref' => 'r2', 'email' => 'b@real.test']);
        DB::table('ph_nullable_codes')->insert(['code' => 'c', 'ref' => 'r3', 'email' => 'c@real.test']);
        DB::table('ph_nullable_codes')->insert(['code' => 'd', 'ref' => 'r4', 'email' => 'd@real.test']);

        config()->set('pii.sanitizers', [PhNullableCode::class => PhNullableCodeSanitizer::class]);
        config()->set('pii.models', [PhNullableCode::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 3));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(4);
        expect(DB::table('ph_nullable_codes')->orderBy('ref')->pluck('email')->all())
            ->toBe(['clean-a@real.test', 'clean-b@real.test', 'clean-c@real.test', 'clean-d@real.test']);
    });

    it('pages a composite key with a row-value filter on SQLite (BUG-20)', function () {
        foreach (range(1, 3) as $org) {
            foreach (range(1, 3) as $member) {
                DB::table('ph_memberships')->insert(['org_id' => $org, 'member_id' => $member, 'email' => "o{$org}m{$member}@x.test"]);
            }
        }

        config()->set('pii.sanitizers', [PhMembership::class => PhMembershipSanitizer::class]);
        config()->set('pii.models', [PhMembership::class]);

        $selects = [];
        DB::listen(function ($query) use (&$selects) {
            if (str_starts_with(strtolower(trim($query->sql)), 'select') && str_contains($query->sql, 'ph_memberships')) {
                $selects[] = $query->sql;
            }
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 4));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(9);

        $paged = array_values(array_filter($selects, fn (string $sql): bool => str_contains($sql, '("org_id", "member_id") > (?, ?)')));
        expect($paged)->toHaveCount(2);

        foreach ($selects as $sql) {
            expect(strtolower($sql))->not->toContain(' or ');
        }
    });
}
