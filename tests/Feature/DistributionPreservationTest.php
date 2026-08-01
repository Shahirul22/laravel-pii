<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class DistUser extends Model
    {
        protected $table = 'dist_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class DistUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'status' => 'active',
                'email' => 'safeEmail',
            ];
        }

        public function categorical(): array
        {
            return ['status'];
        }
    }

    class DistUserUndeclaredCategoricalSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }

        public function categorical(): array
        {
            return ['status'];
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
    use Shahirul22\LaravelPiiSanitizer\ReplacementGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    beforeEach(function () {
        Schema::create('dist_users', function ($table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
        });
    });

    it('preserves a skewed categorical distribution end-to-end against real rows', function () {
        $batches = [];

        for ($i = 0; $i < 840; $i++) {
            $batches[] = ['email' => "active{$i}@example.com", 'status' => 'active'];
        }
        for ($i = 0; $i < 240; $i++) {
            $batches[] = ['email' => "pending{$i}@example.com", 'status' => 'pending'];
        }
        for ($i = 0; $i < 120; $i++) {
            $batches[] = ['email' => "banned{$i}@example.com", 'status' => 'banned'];
        }

        foreach (array_chunk($batches, 200) as $chunk) {
            DB::table('dist_users')->insert($chunk);
        }

        config()->set('pii.sanitizers', [
            DistUser::class => DistUserSanitizer::class,
        ]);

        $sanitizer = Sanitizer::for(DistUser::class);

        expect($sanitizer)->toBeInstanceOf(DistUserSanitizer::class);

        $generator = app(ReplacementGenerator::class);
        $generator->reset();

        $faker = app(Generator::class);
        $faker->seed(20260801);

        DistUser::query()->orderBy('id')->chunkById(200, function ($rows) use ($generator, $sanitizer, $faker) {
            foreach ($rows as $row) {
                $replacements = $generator->forRow($sanitizer, $row, $faker);
                $row->update($replacements);
            }
        });

        $statuses = DB::table('dist_users')->pluck('status');

        foreach ($statuses as $status) {
            expect($status)->toBeIn(['active', 'pending', 'banned']);
        }

        $total = $statuses->count();
        $counts = $statuses->countBy();

        $expected = ['active' => 0.70, 'pending' => 0.20, 'banned' => 0.10];

        foreach ($expected as $value => $p) {
            $observed = ($counts[$value] ?? 0) / $total;
            expect(abs($observed - $p))->toBeLessThanOrEqual(0.05);
        }

        $originalEmails = collect($batches)->pluck('email')->all();
        $currentEmails = DB::table('dist_users')->pluck('email')->all();

        expect(array_intersect($originalEmails, $currentEmails))->toBe([]);
    });

    it('fails fast when a categorical column is not declared in fields()', function () {
        DB::table('dist_users')->insert(['email' => 'a@example.com', 'status' => 'active']);

        config()->set('pii.sanitizers', [
            DistUser::class => DistUserUndeclaredCategoricalSanitizer::class,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            Sanitizer::for(DistUser::class);

            test()->fail('Expected InvalidCategoricalColumnException to be thrown.');
        } catch (InvalidCategoricalColumnException $exception) {
            expect($exception->getMessage())->toContain('status');
        }

        $queries = array_map(fn ($entry) => strtolower($entry['query']), DB::getQueryLog());

        foreach ($queries as $query) {
            expect(str_starts_with($query, 'update') && str_contains($query, 'dist_users'))->toBeFalse();
            expect(str_starts_with($query, 'insert') && str_contains($query, 'dist_users'))->toBeFalse();
        }
    });
}
