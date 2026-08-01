<?php

namespace {
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class RgUser extends Model
    {
        protected $table = 'rg_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RgPair extends Model
    {
        protected $table = 'rg_pairs';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RgUppercaseGenerator implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return 'GEN-'.strtoupper((string) $value);
        }
    }

    class RgAllTypesSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'name' => 'Static Name',
                'note' => fn ($value, $faker, $row) => 'closure-value',
                'email' => 'safeEmail',
                'code' => RgUppercaseGenerator::class,
            ];
        }
    }

    class RgNoteOnlySanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'note' => 'Untouched Note',
            ];
        }
    }

    class RgCategoricalSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['status' => 'fallback-static'];
        }

        public function categorical(): array
        {
            return ['status'];
        }
    }

    /**
     * A sanitizer whose declared columns are each drawn, in order, from a
     * fixed pool of candidate values — deterministic and index-tracked on
     * the instance so retries advance through the pool predictably.
     */
    class RgPoolSanitizer extends Sanitizer
    {
        /** @var array<string, int> */
        private array $indexes = [];

        /**
         * @param  array<string, list<mixed>>  $pools
         */
        public function __construct(private readonly array $pools) {}

        public function fields(): array
        {
            $fields = [];

            foreach ($this->pools as $column => $pool) {
                $fields[$column] = function () use ($column, $pool) {
                    $index = $this->indexes[$column] ?? 0;
                    $this->indexes[$column] = $index + 1;

                    // Wrap around once the pool is exhausted, so a
                    // deliberately-too-small pool keeps offering the same
                    // colliding value(s) forever (needed by the exhaustion
                    // test) rather than falling through to null, which
                    // would never collide.
                    return $pool[$index % count($pool)] ?? null;
                };
            }

            return $fields;
        }
    }
}

namespace {

    use Faker\Factory;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\DistributionSampler;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UniquenessExhaustedException;
    use Shahirul22\LaravelPiiSanitizer\ReplacementGenerator;
    use Shahirul22\LaravelPiiSanitizer\UniqueColumnInspector;
    use Shahirul22\LaravelPiiSanitizer\UniqueValueTracker;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;

    beforeEach(function () {
        Schema::create('rg_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('note')->nullable();
            $table->string('code')->nullable();
            $table->string('status')->nullable();
        });

        Schema::create('rg_pairs', function ($table) {
            $table->id();
            $table->string('tenant');
            $table->string('slug');
            $table->unique(['tenant', 'slug']);
        });
    });

    function rgGenerator(int $maxAttempts = 100, ?DistributionSampler $sampler = null): ReplacementGenerator
    {
        return new ReplacementGenerator(
            new ValueDefinitionResolver,
            new UniqueColumnInspector(app('db')),
            new UniqueValueTracker(app('db')),
            $sampler ?? new DistributionSampler(app('db')),
            $maxAttempts,
        );
    }

    it('round-trips all four value-definition types through forRow()', function () {
        $generator = rgGenerator();
        $sanitizer = new RgAllTypesSanitizer;
        $row = new RgUser(['code' => 'abc']);
        $faker = Factory::create();

        $result = $generator->forRow($sanitizer, $row, $faker);

        expect(array_keys($result))->toEqualCanonicalizing(['name', 'note', 'email', 'code']);
        expect($result['name'])->toBe('Static Name');
        expect($result['note'])->toBe('closure-value');
        expect($result['email'])->toBeString()->and(str_contains($result['email'], '@'))->toBeTrue();
        expect($result['code'])->toBe('GEN-ABC');
    });

    it('produces replacement emails with zero collisions against pre-existing values', function () {
        for ($i = 0; $i < 5; $i++) {
            DB::table('rg_users')->insert(['email' => "existing{$i}@example.com"]);
        }

        $pool = ['existing0@example.com', 'existing1@example.com', 'new0@example.com', 'new1@example.com', 'new2@example.com'];
        $sanitizer = new RgPoolSanitizer(['email' => $pool]);
        $generator = rgGenerator();
        $faker = Factory::create();

        $produced = [];

        for ($i = 0; $i < 3; $i++) {
            $result = $generator->forRow($sanitizer, new RgUser, $faker);
            $produced[] = $result['email'];
        }

        $existing = array_map(fn ($i) => "existing{$i}@example.com", range(0, 4));

        expect(array_intersect($produced, $existing))->toBe([]);
    });

    it('produces zero collisions among newly-generated values only, on an empty table', function () {
        $pool = array_map(fn ($i) => "gen{$i}@example.com", range(0, 199));
        $sanitizer = new RgPoolSanitizer(['email' => $pool]);
        $generator = rgGenerator();
        $faker = Factory::create();

        $produced = [];

        for ($i = 0; $i < 200; $i++) {
            $result = $generator->forRow($sanitizer, new RgUser, $faker);
            $produced[] = $result['email'];
        }

        expect(array_unique($produced))->toHaveCount(200);
    });

    it('throws UniquenessExhaustedException with a descriptive message on exhaustion', function () {
        DB::table('rg_users')->insert(['email' => 'fixed@example.com']);

        $sanitizer = new RgPoolSanitizer(['email' => ['fixed@example.com']]);
        $generator = rgGenerator(maxAttempts: 3);
        $faker = Factory::create();

        try {
            $generator->forRow($sanitizer, new RgUser, $faker);

            test()->fail('Expected UniquenessExhaustedException to be thrown.');
        } catch (UniquenessExhaustedException $exception) {
            expect($exception->getMessage())->toContain(RgUser::class);
            expect($exception->getMessage())->toContain('email');
            expect($exception->getMessage())->toContain('rg_users');
            expect($exception->getMessage())->toContain(RgPoolSanitizer::class);
            expect($exception->getMessage())->toContain('3');
        }
    });

    it('enforces uniqueness on the composite tuple, not per-column, when only one member column is declared', function () {
        DB::table('rg_pairs')->insert(['tenant' => 't1', 'slug' => 'taken']);

        $sanitizer = new RgPoolSanitizer(['slug' => ['taken', 'free']]);
        $generator = rgGenerator();
        $faker = Factory::create();

        $rowT1 = new RgPair(['tenant' => 't1']);
        $result = $generator->forRow($sanitizer, $rowT1, $faker);

        expect($result['slug'])->not->toBe('taken');

        // Same slug value is fine for a different tenant.
        $sanitizerT2 = new RgPoolSanitizer(['slug' => ['taken']]);
        $rowT2 = new RgPair(['tenant' => 't2']);
        $resultT2 = $generator->forRow($sanitizerT2, $rowT2, $faker);

        expect($resultT2['slug'])->toBe('taken');
    });

    it('regenerates both members together when both composite columns are declared', function () {
        DB::table('rg_pairs')->insert(['tenant' => 't1', 'slug' => 'taken']);

        $sanitizer = new RgPoolSanitizer([
            'tenant' => ['t1', 't1', 't3'],
            'slug' => ['taken', 'taken', 'free'],
        ]);
        $generator = rgGenerator();
        $faker = Factory::create();

        $result = $generator->forRow($sanitizer, new RgPair(['tenant' => 't1']), $faker);

        expect([$result['tenant'], $result['slug']])->toBe(['t3', 'free']);
    });

    it('takes the fast path with no retry and no seed query when the declared column has no unique constraint', function () {
        $sanitizer = new RgNoteOnlySanitizer;
        $generator = rgGenerator();
        $faker = Factory::create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $generator->forRow($sanitizer, new RgUser, $faker);

        expect($result)->toBe(['note' => 'Untouched Note']);

        $queries = array_map(fn ($entry) => strtolower($entry['query']), DB::getQueryLog());

        foreach ($queries as $query) {
            expect($query)->not->toContain('distinct');
        }
    });

    it('reset() clears run state so a value produced before it may be produced again', function () {
        $generator = rgGenerator();
        $faker = Factory::create();

        $sanitizer1 = new RgPoolSanitizer(['email' => ['solo@example.com']]);
        $result1 = $generator->forRow($sanitizer1, new RgUser, $faker);

        expect($result1['email'])->toBe('solo@example.com');

        $generator->reset();

        $sanitizer2 = new RgPoolSanitizer(['email' => ['solo@example.com']]);
        $result2 = $generator->forRow($sanitizer2, new RgUser, $faker);

        expect($result2['email'])->toBe('solo@example.com');
    });

    it('draws a categorical column from the sampler and ignores its fields() definition', function () {
        DB::table('rg_users')->insert(['email' => 'a1@example.com', 'status' => 'active']);
        DB::table('rg_users')->insert(['email' => 'a2@example.com', 'status' => 'pending']);
        DB::table('rg_users')->insert(['email' => 'a3@example.com', 'status' => 'banned']);

        $sanitizer = new RgCategoricalSanitizer;
        $generator = rgGenerator();
        $faker = Factory::create();

        $result = $generator->forRow($sanitizer, new RgUser(['status' => 'active']), $faker);

        expect($result['status'])->toBeIn(['active', 'pending', 'banned']);
        expect($result['status'])->not->toBe('fallback-static');
    });

    it('preserves the distribution across 1000 forRow() calls within tolerance', function () {
        for ($i = 0; $i < 70; $i++) {
            DB::table('rg_users')->insert(['email' => "a{$i}@example.com", 'status' => 'active']);
        }
        for ($i = 0; $i < 20; $i++) {
            DB::table('rg_users')->insert(['email' => "p{$i}@example.com", 'status' => 'pending']);
        }
        for ($i = 0; $i < 10; $i++) {
            DB::table('rg_users')->insert(['email' => "b{$i}@example.com", 'status' => 'banned']);
        }

        $sanitizer = new RgCategoricalSanitizer;
        $generator = rgGenerator();
        $faker = Factory::create();
        $faker->seed(20260801);

        $row = new RgUser(['status' => 'active']);

        $draws = [];
        for ($i = 0; $i < 1000; $i++) {
            $result = $generator->forRow($sanitizer, $row, $faker);
            expect(array_keys($result))->toBe(['status']);
            $draws[] = $result['status'];
        }

        $counts = array_count_values($draws);
        $expected = ['active' => 0.70, 'pending' => 0.20, 'banned' => 0.10];

        foreach ($expected as $value => $p) {
            $observed = ($counts[$value] ?? 0) / 1000;
            expect(abs($observed - $p))->toBeLessThanOrEqual(0.05);
        }
    });

    it('falls back to the fields() value-definition when the profile is empty', function () {
        $sanitizer = new RgCategoricalSanitizer;
        $generator = rgGenerator();
        $faker = Factory::create();

        $result = $generator->forRow($sanitizer, new RgUser, $faker);

        expect($result['status'])->toBe('fallback-static');
    });

    it('resolves all four value-definition types unchanged for a non-categorical sanitizer', function () {
        $generator = rgGenerator();
        $sanitizer = new RgAllTypesSanitizer;
        $row = new RgUser(['code' => 'abc']);
        $faker = Factory::create();

        $result = $generator->forRow($sanitizer, $row, $faker);

        expect($result['name'])->toBe('Static Name');
        expect($result['note'])->toBe('closure-value');
        expect($result['email'])->toBeString()->and(str_contains($result['email'], '@'))->toBeTrue();
        expect($result['code'])->toBe('GEN-ABC');
    });

    it('clears the sampler profile cache on reset()', function () {
        DB::table('rg_users')->insert(['email' => 'x1@example.com', 'status' => 'active']);

        $sanitizer = new RgCategoricalSanitizer;
        $generator = rgGenerator();
        $faker = Factory::create();

        $result1 = $generator->forRow($sanitizer, new RgUser(['status' => 'active']), $faker);
        expect($result1['status'])->toBe('active');

        DB::table('rg_users')->insert(['email' => 'x2@example.com', 'status' => 'pending']);

        $generator->reset();

        $sawPending = false;
        for ($i = 0; $i < 50; $i++) {
            $result2 = $generator->forRow($sanitizer, new RgUser(['status' => 'active']), $faker);
            if ($result2['status'] === 'pending') {
                $sawPending = true;
                break;
            }
        }

        expect($sawPending)->toBeTrue();
    });
}
