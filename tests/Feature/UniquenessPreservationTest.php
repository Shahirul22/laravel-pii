<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class FeatUniqueUser extends Model
    {
        protected $table = 'feat_unique_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class FeatUniqueUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'email' => 'safeEmail',
            ];
        }
    }
}

namespace {

    use Faker\Factory;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ReplacementGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    beforeEach(function () {
        Schema::create('feat_unique_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
        });

        for ($i = 0; $i < 50; $i++) {
            DB::table('feat_unique_users')->insert(['email' => "pre-{$i}@example.com"]);
        }
    });

    it('generates uniqueness-preserving replacement values end-to-end against a real unique-constrained column', function () {
        config()->set('pii.sanitizers', [
            FeatUniqueUser::class => FeatUniqueUserSanitizer::class,
        ]);

        $sanitizer = Sanitizer::for(FeatUniqueUser::class);
        expect($sanitizer)->toBeInstanceOf(FeatUniqueUserSanitizer::class);

        $generator = app(ReplacementGenerator::class);
        $faker = Factory::create();

        $rows = DB::table('feat_unique_users')->orderBy('id')->get();

        $produced = [];

        foreach ($rows as $row) {
            $model = new FeatUniqueUser((array) $row);
            $model->exists = true;

            $result = $generator->forRow($sanitizer, $model, $faker);

            $produced[] = $result['email'];

            DB::table('feat_unique_users')->where('id', $row->id)->update(['email' => $result['email']]);
        }

        $preExisting = array_map(fn ($i) => "pre-{$i}@example.com", range(0, 49));

        expect(array_intersect($produced, $preExisting))->toBe([]);
        expect(array_unique($produced))->toHaveCount(count($produced));
        expect(DB::table('feat_unique_users')->distinct()->count('email'))->toBe(count($produced));
    });
}
