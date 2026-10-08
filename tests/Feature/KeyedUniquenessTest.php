<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    abstract class KuModel extends Model
    {
        protected $guarded = [];

        public $timestamps = false;
    }

    class KuCode extends KuModel
    {
        protected $table = 'ku_codes';
    }

    class KuMembership extends KuModel
    {
        protected $table = 'ku_memberships';
    }

    class KuBadge extends KuModel
    {
        protected $table = 'ku_badges';
    }

    class KuHandle extends KuModel
    {
        protected $table = 'ku_handles';
    }

    class KuAlt extends KuModel
    {
        protected $table = 'ku_alts';
    }

    class KuMirror extends KuModel
    {
        protected $table = 'ku_mirrors';
    }

    class KuUser extends KuModel
    {
        protected $table = 'ku_users';
    }

    class KuTiny extends KuModel
    {
        protected $table = 'ku_tiny';
    }

    class KuCodeSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => Keyed::pattern('code', '###')];
        }
    }

    class KuMembershipSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'member_code' => Keyed::pattern('member', '###'),
                'tenant' => fn ($value) => $value,
            ];
        }
    }

    class KuBadgeSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['badge_no' => Keyed::pattern('badge', '1###')];
        }
    }

    class KuHandleSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['handle' => Keyed::pattern('handle', '??')];
        }
    }

    class KuAltSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['alt' => Keyed::pattern('alt', '####')];
        }
    }

    class KuCode2Sanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => Keyed::pattern('code2', '##')];
        }
    }

    class KuTinySanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['code' => Keyed::pattern('tiny', '#')];
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UniquenessExhaustedException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\RunReport;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Values\Format\Pattern;
    use Shahirul22\LaravelPiiSanitizer\Values\KeyedResolver;

    const KU_KEY = "\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01";

    /**
     * @param  array<class-string, class-string>  $map  model => sanitizer, in run order
     */
    function kuRun(array $map, int $faker = 1): RunReport
    {
        config()->set('pii.sanitizers', $map);
        config()->set('pii.models', array_keys($map));

        app(Generator::class)->seed($faker);

        return app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 25));
    }

    beforeEach(function () {
        config()->set('pii.keyed.key', KU_KEY);
    });

    it('keeps a unique column collision-free and deterministic by probing, not retrying (AC-2)', function () {
        Schema::create('ku_codes', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });

        $originals = array_map(fn (int $i): string => sprintf('%03d', $i), range(0, 299));
        $seed = function () use ($originals): void {
            DB::table('ku_codes')->truncate();

            foreach ($originals as $original) {
                DB::table('ku_codes')->insert(['code' => $original]);
            }
        };
        $seed();

        $report = kuRun([KuCode::class => KuCodeSanitizer::class], faker: 1);
        expect($report->failed())->toBeFalse();

        $first = DB::table('ku_codes')->orderBy('id')->pluck('code', 'id')->all();

        expect(array_unique($first))->toHaveCount(300);
        expect(array_intersect($first, $originals))->toBe([]);

        // At least one row took a probe above 0, proving the deterministic
        // collision path ran rather than a lucky probe-0 run.
        $probedPast0 = 0;

        foreach ($originals as $index => $original) {
            $probe0 = KeyedResolver::candidate(KU_KEY, 'code', $original, 0, new Pattern('###'), $original);

            if ($first[$index + 1] !== $probe0) {
                $probedPast0++;
            }
        }

        expect($probedPast0)->toBeGreaterThan(0);

        $seed();
        $report = kuRun([KuCode::class => KuCodeSanitizer::class], faker: 999);
        expect($report->failed())->toBeFalse();

        expect(DB::table('ku_codes')->orderBy('id')->pluck('code', 'id')->all())->toBe($first);
    });

    it('stays collision-free on a composite constraint with a keyed and a non-keyed member', function () {
        Schema::create('ku_memberships', function ($table) {
            $table->id();
            $table->string('tenant');
            $table->string('member_code');
            $table->unique(['tenant', 'member_code']);
        });

        $seed = function (): void {
            DB::table('ku_memberships')->truncate();

            foreach (['t1', 't2'] as $tenant) {
                for ($i = 0; $i < 20; $i++) {
                    DB::table('ku_memberships')->insert(['tenant' => $tenant, 'member_code' => "m{$i}"]);
                }
            }
        };
        $seed();

        $map = [KuMembership::class => KuMembershipSanitizer::class];

        expect(kuRun($map, faker: 1)->failed())->toBeFalse();

        $rows = DB::table('ku_memberships')->orderBy('id')->get();
        $first = $rows->map(fn ($r) => [$r->tenant, $r->member_code])->all();

        expect(collect($first)->map(fn ($tuple) => implode('|', $tuple))->unique())->toHaveCount(40);
        expect($rows[0]->member_code)->toBe($rows[20]->member_code);

        $seed();
        expect(kuRun($map, faker: 999)->failed())->toBeFalse();

        $second = DB::table('ku_memberships')->orderBy('id')->get()->map(fn ($r) => [$r->tenant, $r->member_code])->all();

        expect($second)->toBe($first);
    });

    it('stays collision-free on an integer unique column', function () {
        Schema::create('ku_badges', function ($table) {
            $table->id();
            $table->integer('badge_no')->unique();
        });

        $seed = function (): void {
            DB::table('ku_badges')->truncate();

            foreach (range(1000, 1199) as $number) {
                DB::table('ku_badges')->insert(['badge_no' => $number]);
            }
        };
        $seed();

        $map = [KuBadge::class => KuBadgeSanitizer::class];

        expect(kuRun($map, faker: 1)->failed())->toBeFalse();

        $first = DB::table('ku_badges')->orderBy('id')->pluck('badge_no', 'id')->all();

        expect(array_unique($first))->toHaveCount(200);
        expect(array_intersect($first, range(1000, 1199)))->toBe([]);

        $seed();
        expect(kuRun($map, faker: 999)->failed())->toBeFalse();

        expect(DB::table('ku_badges')->orderBy('id')->pluck('badge_no', 'id')->all())->toBe($first);
    });

    it('compares case-insensitively so a case-insensitive collation never rejects a write', function () {
        Schema::create('ku_handles', function ($table) {
            $table->id();
            $table->string('handle')->collation('NOCASE')->unique();
        });

        $originals = [];

        foreach (range('a', 'z') as $a) {
            foreach (range('a', 'z') as $b) {
                $originals[] = $a.$b;
            }
        }

        $originals = array_slice($originals, 0, 100);

        foreach ($originals as $original) {
            DB::table('ku_handles')->insert(['handle' => $original]);
        }

        $report = kuRun([KuHandle::class => KuHandleSanitizer::class]);

        expect($report->failed())->toBeFalse();

        foreach (DB::table('ku_handles')->pluck('handle') as $handle) {
            expect(in_array(strtolower($handle), $originals, true))->toBeFalse();
        }
    });

    it('leaves NULL rows NULL on a nullable unique keyed column', function () {
        Schema::create('ku_alts', function ($table) {
            $table->id();
            $table->string('alt')->nullable()->unique();
        });

        for ($i = 0; $i < 10; $i++) {
            DB::table('ku_alts')->insert(['alt' => "alt-{$i}"]);
        }

        for ($i = 0; $i < 5; $i++) {
            DB::table('ku_alts')->insert(['alt' => null]);
        }

        $report = kuRun([KuAlt::class => KuAltSanitizer::class]);

        expect($report->failed())->toBeFalse();
        expect(DB::table('ku_alts')->whereNull('alt')->count())->toBe(5);

        $values = DB::table('ku_alts')->whereNotNull('alt')->pluck('alt')->all();

        expect($values)->toHaveCount(10);
        expect(array_unique($values))->toHaveCount(10);
    });

    it('makes a mirror processed first avoid the unique source column\'s originals', function () {
        Schema::create('ku_mirrors', function ($table) {
            $table->id();
            $table->string('code');
        });
        Schema::create('ku_users', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });

        $originals = array_map(fn (int $i): string => sprintf('%02d', $i), range(0, 39));

        foreach ($originals as $original) {
            DB::table('ku_mirrors')->insert(['code' => $original]);
            DB::table('ku_users')->insert(['code' => $original]);
        }

        // The mirror is listed (and therefore processed) before the unique source.
        $report = kuRun([KuMirror::class => KuCode2Sanitizer::class, KuUser::class => KuCode2Sanitizer::class]);

        expect($report->failed())->toBeFalse();

        $users = DB::table('ku_users')->orderBy('id')->pluck('code')->all();
        $mirrors = DB::table('ku_mirrors')->orderBy('id')->pluck('code')->all();

        expect(array_unique($users))->toHaveCount(40);
        expect(array_intersect($users, $originals))->toBe([]);
        expect($mirrors)->toBe($users);
    });

    it('fails the chunk with keyedProbesExhausted when the shape domain is too small', function () {
        Schema::create('ku_tiny', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });

        for ($i = 0; $i < 20; $i++) {
            DB::table('ku_tiny')->insert(['code' => "x{$i}"]);
        }

        $report = kuRun([KuTiny::class => KuTinySanitizer::class]);

        expect($report->failed())->toBeTrue();

        $failed = collect($report->models[0]->chunks)->first(fn ($chunk) => $chunk->failureClass !== null);

        expect($failed->failureClass)->toBe(UniquenessExhaustedException::class);
        expect($failed->failureMessage)->toContain('probes');
    });
}
