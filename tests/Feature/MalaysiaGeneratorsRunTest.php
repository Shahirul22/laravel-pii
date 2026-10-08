<?php

namespace {
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
    use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

    class MlyPerson extends Model
    {
        protected $table = 'mly_people';

        protected $guarded = [];

        public $timestamps = false;
    }

    class MlyMaybankGenerator implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return Malaysia::bankAccount('Maybank')($value, $faker, $row);
        }
    }

    class MlyPersonSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'nric' => Keyed::using('nric', Malaysia::nric()),
                'nric_mirror' => Keyed::using('nric', Malaysia::nric()),
                'ic_hyphen' => Malaysia::nric(true, 'female'),
                'optional_nric' => Malaysia::nric(),
                'sst' => Malaysia::sst(),
                'state' => Malaysia::state(),
                'region' => Keyed::using('region', Malaysia::state()),
                'bank_account' => Malaysia::bankAccount(),
                'shorthand_nric' => 'malaysiaNric',
                'shorthand_sst' => 'malaysiaSst',
                'shorthand_state' => 'malaysiaState',
                'shorthand_bank' => 'malaysiaBankAccount',
                'closure_sst' => fn ($value, $faker, $row) => Malaysia::sst()($value, $faker, $row),
                'generator_bank' => MlyMaybankGenerator::class,
                'name' => 'name',
                'status' => 'redacted',
            ];
        }
    }

    class MlyBadBankSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['bank_account' => Malaysia::bankAccount('Nope')];
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Tests\Support\MalaysiaFormats;

    beforeEach(function () {
        Schema::create('mly_people', function ($table) {
            $table->id();
            $table->string('nric')->unique();
            $table->string('nric_mirror')->nullable();
            $table->string('optional_nric')->nullable();
            foreach (['ic_hyphen', 'sst', 'state', 'region', 'bank_account', 'shorthand_nric', 'shorthand_sst', 'shorthand_state', 'shorthand_bank', 'closure_sst', 'generator_bank', 'name', 'status'] as $column) {
                $table->string($column);
            }
        });

        config()->set('pii.keyed.key', str_repeat("\x01", 32));
        config()->set('pii.sanitizers', [MlyPerson::class => MlyPersonSanitizer::class]);
        config()->set('pii.models', [MlyPerson::class]);

        $this->seedMly = function (): void {
            DB::table('mly_people')->truncate();

            $filler = ['ic_hyphen' => 'orig', 'sst' => 'orig', 'state' => 'orig', 'bank_account' => 'orig', 'shorthand_nric' => 'orig', 'shorthand_sst' => 'orig', 'shorthand_state' => 'orig', 'shorthand_bank' => 'orig', 'closure_sst' => 'orig', 'generator_bank' => 'orig', 'status' => 'active'];

            DB::table('mly_people')->insert([
                $filler + ['nric' => '900101015555', 'nric_mirror' => null, 'optional_nric' => null, 'region' => 'Selangor', 'name' => 'Aminah Yusof'],
                $filler + ['nric' => '880202026666', 'nric_mirror' => '900101015555', 'optional_nric' => '770303037777', 'region' => 'Selangor', 'name' => 'Lim Wei'],
                $filler + ['nric' => '770303037777', 'nric_mirror' => null, 'optional_nric' => null, 'region' => 'Johor', 'name' => 'Raj Kumar'],
            ]);
        };

        ($this->seedMly)();
    });

    it('writes structurally valid Malaysia values without changing the Faker locale', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();
        expect(config('app.faker_locale'))->toBe('en_US');

        $literals = ['malaysiaNric', 'malaysiaSst', 'malaysiaState', 'malaysiaBankAccount'];

        foreach (DB::table('mly_people')->orderBy('id')->get() as $row) {
            expect(MalaysiaFormats::nricIsValid($row->nric))->toBeTrue();
            if ($row->optional_nric !== null) {
                expect(MalaysiaFormats::nricIsValid($row->optional_nric))->toBeTrue();
            }
            expect(MalaysiaFormats::nricIsValid($row->shorthand_nric))->toBeTrue();
            expect(MalaysiaFormats::nricIsValid($row->ic_hyphen, true, 'female'))->toBeTrue();

            foreach (['sst', 'shorthand_sst', 'closure_sst'] as $column) {
                expect(MalaysiaFormats::sstIsValid($row->{$column}))->toBeTrue();
            }

            foreach (['state', 'region', 'shorthand_state'] as $column) {
                expect(in_array($row->{$column}, MalaysiaFormats::STATES, true))->toBeTrue();
            }

            expect(MalaysiaFormats::bankAccountIsValid($row->bank_account))->toBeTrue();
            expect(MalaysiaFormats::bankAccountIsValid($row->shorthand_bank))->toBeTrue();
            expect(MalaysiaFormats::bankAccountIsValid($row->generator_bank, 'Maybank'))->toBeTrue();

            expect($row->status)->toBe('redacted');
            expect($row->name)->toBeString()->not->toBe('');
            expect(in_array($row->name, ['Aminah Yusof', 'Lim Wei', 'Raj Kumar'], true))->toBeFalse();

            foreach (['shorthand_nric', 'shorthand_sst', 'shorthand_state', 'shorthand_bank'] as $column) {
                expect(in_array($row->{$column}, $literals, true))->toBeFalse();
            }
        }
    });

    it('keeps keyed Malaysia values unique, mirrored and null-preserving', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        $rows = DB::table('mly_people')->orderBy('id')->get();
        $originals = ['900101015555', '880202026666', '770303037777'];

        expect($rows->pluck('nric')->unique())->toHaveCount(3);
        foreach ($rows as $i => $row) {
            expect($row->nric)->not->toBe($originals[$i]);
        }

        expect($rows[1]->nric_mirror)->toBe($rows[0]->nric);
        expect($rows[0]->nric_mirror)->toBeNull();
        expect($rows[2]->nric_mirror)->toBeNull();
        expect($rows[0]->optional_nric)->toBeNull();
        expect($rows[2]->optional_nric)->toBeNull();
        expect($rows[0]->region)->toBe($rows[1]->region);
    });

    it('is deterministic for keyed Malaysia columns across runs', function () {
        $snapshot = fn () => DB::table('mly_people')->orderBy('id')->get(['id', 'nric', 'nric_mirror', 'region'])->map(fn ($r) => (array) $r)->all();

        app(Generator::class)->seed(1);
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));
        $first = $snapshot();

        ($this->seedMly)();

        app(Generator::class)->seed(999);
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($snapshot())->toBe($first);
    });

    it('rejects an unknown bank before any row is touched', function () {
        config()->set('pii.sanitizers', [MlyPerson::class => MlyBadBankSanitizer::class]);

        $before = DB::table('mly_people')->orderBy('id')->get()->all();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2)))->toThrow(InvalidArgumentException::class);
        expect(DB::table('mly_people')->orderBy('id')->get()->all())->toEqual($before);
    });
}
