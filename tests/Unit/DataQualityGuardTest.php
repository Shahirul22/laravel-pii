<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class DqGuardUser extends Model
    {
        protected $table = 'dq_guard_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class DqGuardCleanSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['status' => 'active'];
        }

        public function categorical(): array
        {
            return ['status'];
        }
    }

    class DqGuardUndeclaredSanitizer extends Sanitizer
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

    class DqGuardUniqueSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }

        public function categorical(): array
        {
            return ['email'];
        }
    }

    class DqGuardNoCategoricalSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class DqGuardNotAModel
    {
        //
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\DataQualityGuard;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\UniqueColumnInspector;

    function dqGuard(): DataQualityGuard
    {
        return new DataQualityGuard(new UniqueColumnInspector(app('db')));
    }

    it('throws when a categorical column is not declared in fields()', function () {
        try {
            dqGuard()->assertValid(new DqGuardUndeclaredSanitizer, DqGuardUser::class);

            test()->fail('Expected InvalidCategoricalColumnException to be thrown.');
        } catch (InvalidCategoricalColumnException $exception) {
            expect($exception->getMessage())->toContain(DqGuardUser::class);
            expect($exception->getMessage())->toContain('status');
            expect($exception->getMessage())->toContain('categorical()');
            expect($exception->getMessage())->toContain('fields()');
        }
    });

    it('throws when a categorical column carries a unique constraint', function () {
        Schema::create('dq_guard_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
        });

        try {
            dqGuard()->assertValid(new DqGuardUniqueSanitizer, DqGuardUser::class);

            test()->fail('Expected InvalidCategoricalColumnException to be thrown.');
        } catch (InvalidCategoricalColumnException $exception) {
            expect($exception->getMessage())->toContain('unique constraint');
            expect($exception->getMessage())->toContain('dq_guard_users');
        }
    });

    it('throws when a categorical column is the primary key', function () {
        Schema::create('dq_guard_users', function ($table) {
            $table->id();
            $table->string('email')->nullable();
        });

        $sanitizer = new class extends Sanitizer
        {
            public function fields(): array
            {
                return ['id' => 1];
            }

            public function categorical(): array
            {
                return ['id'];
            }
        };

        try {
            dqGuard()->assertValid($sanitizer, DqGuardUser::class);

            test()->fail('Expected InvalidCategoricalColumnException to be thrown.');
        } catch (InvalidCategoricalColumnException $exception) {
            expect($exception->getMessage())->toContain('unique constraint');
        }
    });

    it('passes cleanly for a valid categorical declaration', function () {
        Schema::create('dq_guard_users', function ($table) {
            $table->id();
            $table->string('status')->nullable();
        });

        dqGuard()->assertValid(new DqGuardCleanSanitizer, DqGuardUser::class);

        expect(true)->toBeTrue();
    });

    it('performs no query when categorical() is empty', function () {
        DB::flushQueryLog();
        DB::enableQueryLog();

        dqGuard()->assertValid(new DqGuardNoCategoricalSanitizer, DqGuardUser::class);

        expect(DB::getQueryLog())->toBe([]);
    });

    it('accepts a model instance as well as a class-string', function () {
        Schema::create('dq_guard_users', function ($table) {
            $table->id();
            $table->string('status')->nullable();
        });

        dqGuard()->assertValid(new DqGuardCleanSanitizer, new DqGuardUser);

        expect(true)->toBeTrue();
    });

    it('throws InvalidConfigurationException with the offending class named, when the model resolves to a non-Model instance', function () {
        try {
            dqGuard()->assertValid(new DqGuardCleanSanitizer, DqGuardNotAModel::class);

            test()->fail('Expected InvalidConfigurationException to be thrown.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain(DqGuardNotAModel::class);
            expect($exception->getMessage())->toContain('not a valid Eloquent model class');
        }
    });
}
