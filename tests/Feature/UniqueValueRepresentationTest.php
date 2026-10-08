<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class UvrBadge extends Model
    {
        protected $table = 'uvr_badges';

        protected $guarded = [];

        public $timestamps = false;
    }

    class UvrBadgeSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            // numberBetween() returns an integer; the string is what numerify() and most formatters give.
            return ['badge' => fn ($value, $faker) => (string) $faker->numberBetween(1, 600)];
        }
    }

    class UvrSlot extends Model
    {
        protected $table = 'uvr_slots';

        protected $guarded = [];

        public $timestamps = false;

        protected $casts = ['starts_at' => 'datetime', 'active' => 'boolean'];
    }

    class UvrSlotSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['label' => fn ($value, $faker) => $faker->randomElement(range('a', 'l'))];
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    it('sees an integer already in a unique column as taken when the replacement is the same number as a string (BUG-23)', function () {
        Schema::create('uvr_badges', function ($table) {
            $table->id();
            $table->integer('badge')->unique();
        });

        foreach (array_chunk(array_map(fn (int $badge): array => ['badge' => $badge], range(1, 200)), 100) as $rows) {
            DB::table('uvr_badges')->insert($rows);
        }

        config()->set('pii.sanitizers', [UvrBadge::class => UvrBadgeSanitizer::class]);
        config()->set('pii.models', [UvrBadge::class]);

        app(Generator::class)->seed(23);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 50));

        expect($report->failed())->toBeFalse();

        $badges = DB::table('uvr_badges')->pluck('badge')->all();

        expect($badges)->toHaveCount(200);
        expect(array_unique($badges))->toHaveCount(200);
        expect(array_intersect($badges, range(1, 200)))->toBe([]);
    });

    it('compares an undeclared unique member with a cast by its stored value (BUG-24)', function (string $member, array $values) {
        Schema::create('uvr_slots', function ($table) {
            $table->id();
            $table->string('label');
            $table->dateTime('starts_at');
            $table->boolean('active');
        });

        Schema::table('uvr_slots', function ($table) use ($member) {
            $table->unique(['label', $member]);
        });

        $rows = [];

        foreach ($values as $index => $value) {
            foreach (range('a', 'e') as $label) {
                $rows[] = ['label' => $label, 'starts_at' => '2024-01-0'.($index + 1).' 09:00:00', 'active' => $index, $member => $value];
            }
        }

        DB::table('uvr_slots')->insert($rows);

        config()->set('pii.sanitizers', [UvrSlot::class => UvrSlotSanitizer::class]);
        config()->set('pii.models', [UvrSlot::class]);

        app(Generator::class)->seed(24);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 4));

        expect($report->failed())->toBeFalse();

        foreach ($values as $value) {
            $labels = DB::table('uvr_slots')->where($member, $value)->pluck('label')->all();

            expect($labels)->toHaveCount(5);
            expect(array_unique($labels))->toHaveCount(5);
            expect(array_intersect($labels, range('a', 'e')))->toBe([]);
        }
    })->with([
        'datetime cast' => ['starts_at', ['2024-01-01 09:00:00', '2024-01-02 09:00:00']],
        'boolean cast' => ['active', [0, 1]],
    ]);
}
