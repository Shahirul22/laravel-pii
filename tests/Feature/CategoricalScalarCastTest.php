<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    enum CscPlan: string
    {
        case Free = 'free';
        case Pro = 'pro';
        case Team = 'team';
    }

    class CscAccount extends Model
    {
        protected $table = 'csc_accounts';

        protected $guarded = [];

        public $timestamps = false;

        protected $casts = [
            'plan' => CscPlan::class,
            'active' => 'boolean',
            'seats' => 'integer',
            'joined_on' => 'date',
            'score' => 'decimal:2',
            'tier' => 'string',
        ];
    }

    class CscAccountSanitizer extends Sanitizer
    {
        /** @var list<string> */
        public static array $columns = ['plan', 'active', 'seats', 'joined_on', 'score', 'tier'];

        public function fields(): array
        {
            return array_fill_keys(static::$columns, 'word');
        }

        public function categorical(): array
        {
            return static::$columns;
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    // BUG-11 (round 2): the round 1 fix refused every categorical() column
    // with a cast. Only a cast that changes the meaning of a raw stored value
    // when it encodes it again (array, json, encrypted, hashed, custom
    // classes, set mutators) is refused; scalar, date, decimal and enum casts
    // run as they did in v0.1.0 and keep the distribution.

    beforeEach(function () {
        CscAccountSanitizer::$columns = ['plan', 'active', 'seats', 'joined_on', 'score', 'tier'];

        Schema::create('csc_accounts', function ($table) {
            $table->id();
            $table->string('plan');
            $table->boolean('active');
            $table->integer('seats');
            $table->date('joined_on');
            $table->decimal('score', 8, 2);
            $table->string('tier');
        });

        $rows = [];

        for ($i = 0; $i < 300; $i++) {
            $rows[] = [
                'plan' => $i < 210 ? 'free' : ($i < 270 ? 'pro' : 'team'),
                'active' => $i % 5 === 0 ? 0 : 1,
                'seats' => $i % 10 < 7 ? 1 : 25,
                'joined_on' => $i % 2 === 0 ? '2024-01-01' : '2024-06-01',
                'score' => $i % 4 === 0 ? '1.50' : '9.25',
                'tier' => $i % 3 === 0 ? 'gold' : 'silver',
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('csc_accounts')->insert($chunk);
        }

        config()->set('pii.sanitizers', [CscAccount::class => CscAccountSanitizer::class]);
        config()->set('pii.models', [CscAccount::class]);
    });

    it('runs categorical() on enum, boolean, integer, date, decimal and string casts and keeps the distribution', function () {
        app(Generator::class)->seed(20261008);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 50));

        expect($report->failed())->toBeFalse();

        $accounts = CscAccount::query()->orderBy('id')->get();

        $share = fn (callable $pick, mixed $value): float => $accounts->filter(fn (CscAccount $account): bool => $pick($account) === $value)->count() / $accounts->count();

        foreach ($accounts as $account) {
            expect($account->plan)->toBeInstanceOf(CscPlan::class);
            expect($account->active)->toBeBool();
            expect($account->seats)->toBeIn([1, 25]);
            expect($account->joined_on->toDateString())->toBeIn(['2024-01-01', '2024-06-01']);
            expect($account->score)->toBeIn(['1.50', '9.25']);
            expect($account->tier)->toBeIn(['gold', 'silver']);
        }

        expect(abs($share(fn ($a) => $a->plan, CscPlan::Free) - 0.70))->toBeLessThanOrEqual(0.08);
        expect(abs($share(fn ($a) => $a->plan, CscPlan::Team) - 0.10))->toBeLessThanOrEqual(0.08);
        expect(abs($share(fn ($a) => $a->active, false) - 0.20))->toBeLessThanOrEqual(0.08);
        expect(abs($share(fn ($a) => $a->seats, 1) - 0.70))->toBeLessThanOrEqual(0.08);
        expect(abs($share(fn ($a) => $a->score, '1.50') - 0.25))->toBeLessThanOrEqual(0.08);

        // The raw stored values stay in the stored vocabulary: nothing was encoded a second time.
        expect(DB::table('csc_accounts')->distinct()->pluck('plan')->sort()->values()->all())->toBe(['free', 'pro', 'team']);
        expect(DB::table('csc_accounts')->distinct()->pluck('tier')->sort()->values()->all())->toBe(['gold', 'silver']);
    });

    it('runs a categorical enum cast on its own', function () {
        CscAccountSanitizer::$columns = ['plan'];

        expect(app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 50))->failed())->toBeFalse();
        expect(DB::table('csc_accounts')->distinct()->pluck('plan')->sort()->values()->all())->toBe(['free', 'pro', 'team']);
    });

    it('runs a categorical boolean and integer cast on their own', function () {
        CscAccountSanitizer::$columns = ['active', 'seats'];

        expect(app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 50))->failed())->toBeFalse();
        expect(DB::table('csc_accounts')->distinct()->pluck('active')->sort()->values()->all())->toEqual([0, 1]);
        expect(DB::table('csc_accounts')->distinct()->pluck('seats')->sort()->values()->all())->toEqual([1, 25]);
    });
}
