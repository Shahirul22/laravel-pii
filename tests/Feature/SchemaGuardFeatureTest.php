<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class FeatureCompany extends Model
    {
        protected $table = 'feature_companies';

        protected $guarded = [];

        public $timestamps = false;
    }

    class FeatureUser extends Model
    {
        protected $table = 'feature_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class FeatureUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['company_id' => 'randomNumber'];
        }
    }

    class FeatureCleanUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    beforeEach(function () {
        Schema::create('feature_companies', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('feature_users', function ($table) {
            $table->id();
            $table->string('email')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('feature_companies');
        });
    });

    it('throws UnsafeColumnException end-to-end from Sanitizer::for() when a declared column is a real foreign key', function () {
        config()->set('pii.sanitizers', [
            FeatureUser::class => FeatureUserSanitizer::class,
        ]);

        try {
            Sanitizer::for(FeatureUser::class);

            $this->fail('Expected UnsafeColumnException to be thrown.');
        } catch (UnsafeColumnException $exception) {
            expect($exception->getMessage())->toContain(FeatureUser::class);
            expect($exception->getMessage())->toContain('company_id');
        }
    });

    it('resolves normally through Sanitizer::for() when no declared column is FK-involved', function () {
        config()->set('pii.sanitizers', [
            FeatureUser::class => FeatureCleanUserSanitizer::class,
        ]);

        expect(Sanitizer::for(FeatureUser::class))->toBeInstanceOf(FeatureCleanUserSanitizer::class);
    });

    it('rejects before any row is read or written', function () {
        config()->set('pii.sanitizers', [
            FeatureUser::class => FeatureUserSanitizer::class,
        ]);

        DB::table('feature_companies')->insert(['name' => 'Acme']);
        DB::table('feature_users')->insert(['company_id' => 1, 'email' => 'a@example.com']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            Sanitizer::for(FeatureUser::class);
        } catch (UnsafeColumnException) {
            // expected
        }

        $queries = array_map(fn ($entry) => strtolower($entry['query']), DB::getQueryLog());

        foreach ($queries as $query) {
            expect($query)->not->toContain('select * from "feature_users"');
            expect(str_starts_with($query, 'update') && str_contains($query, 'feature_users'))->toBeFalse();
            expect(str_starts_with($query, 'insert') && str_contains($query, 'feature_users'))->toBeFalse();
        }
    });
}
