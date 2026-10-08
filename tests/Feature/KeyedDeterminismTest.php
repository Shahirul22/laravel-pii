<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class KdPerson extends Model
    {
        protected $table = 'kd_people';

        protected $guarded = [];

        public $timestamps = false;
    }

    class KdContact extends Model
    {
        protected $table = 'kd_contacts';

        protected $guarded = [];

        public $timestamps = false;
    }

    class KdPersonSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'nric' => Keyed::pattern('nric', '######-??-####'),
                'alt_nric' => Keyed::pattern('nric', '######-??-####'),
                'region' => Keyed::pattern('region', '#'),
            ];
        }
    }

    class KdContactSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['nric' => Keyed::pattern('nric', '######-??-####')];
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    function kdSeed(): void
    {
        DB::table('kd_people')->truncate();
        DB::table('kd_contacts')->truncate();

        DB::table('kd_people')->insert([
            ['nric' => '900101015555', 'alt_nric' => '900101015555', 'region' => null],
            ['nric' => '900101015555', 'alt_nric' => '880202026666', 'region' => null],
            ['nric' => '770303037777', 'alt_nric' => null, 'region' => null],
        ]);

        for ($i = 0; $i < 30; $i++) {
            DB::table('kd_people')->insert(['nric' => "filler-{$i}", 'alt_nric' => null, 'region' => "r{$i}"]);
        }

        DB::table('kd_contacts')->insert([['nric' => '900101015555'], ['nric' => '880202026666']]);
    }

    /**
     * @return array<string, mixed>
     */
    function kdSnapshot(): array
    {
        return [
            'people' => DB::table('kd_people')->orderBy('id')->get(['id', 'nric', 'alt_nric'])->map(fn ($r) => (array) $r)->all(),
            'contacts' => DB::table('kd_contacts')->orderBy('id')->get(['id', 'nric'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    beforeEach(function () {
        Schema::create('kd_people', function ($table) {
            $table->id();
            $table->string('nric');
            $table->string('alt_nric')->nullable();
            $table->string('region')->nullable();
        });

        Schema::create('kd_contacts', function ($table) {
            $table->id();
            $table->string('nric');
        });

        config()->set('pii.keyed.key', str_repeat("\x01", 32));
        config()->set('pii.sanitizers', [
            KdPerson::class => KdPersonSanitizer::class,
            KdContact::class => KdContactSanitizer::class,
        ]);
        config()->set('pii.models', [KdPerson::class, KdContact::class]);

        kdSeed();
    });

    it('maps the same input to the same output across rows', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();

        $people = DB::table('kd_people')->orderBy('id')->get();

        expect($people[0]->nric)->toBe($people[1]->nric);
        expect($people[0]->nric)->not->toBe('900101015555');
    });

    it('maps the same input to the same output across columns and tables', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        $people = DB::table('kd_people')->orderBy('id')->get();
        $contacts = DB::table('kd_contacts')->orderBy('id')->get();

        expect($people[0]->alt_nric)->toBe($people[1]->nric);
        expect($people[0]->alt_nric)->toBe($contacts[0]->nric);
        expect($people[1]->alt_nric)->toBe($contacts[1]->nric);
        expect($people[2]->alt_nric)->toBeNull();
    });

    it('produces identical output across repeated runs on reseeded data', function () {
        app(Generator::class)->seed(1);
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));
        $first = kdSnapshot();

        kdSeed();

        app(Generator::class)->seed(999);
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));
        $second = kdSnapshot();

        expect($second)->toBe($first);
    });

    it('lets a low-cardinality shape map many inputs onto few values on a non-unique column', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();

        $regions = DB::table('kd_people')->whereNotNull('region')->pluck('region')->all();

        expect($regions)->toHaveCount(30);

        foreach ($regions as $region) {
            expect($region)->toMatch('/^[0-9]$/');
        }

        expect(count(array_unique($regions)))->toBeLessThanOrEqual(10);
    });
}
