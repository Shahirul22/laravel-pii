<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class GuardCompany extends Model
    {
        protected $table = 'guard_companies';

        protected $guarded = [];

        public $timestamps = false;
    }

    class GuardUser extends Model
    {
        protected $table = 'guard_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class GuardOrder extends Model
    {
        protected $table = 'guard_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class GuardOutboundFkSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['company_id' => 'name'];
        }
    }

    class GuardInboundRefSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['id' => 'randomNumber'];
        }
    }

    class GuardCleanSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail', 'name' => 'name'];
        }
    }

    class GuardUnknownColumnSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['emial' => 'safeEmail'];
        }
    }

    class GuardEmptySanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\SchemaGuard;

    beforeEach(function () {
        Schema::create('guard_companies', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('guard_users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('guard_companies');
        });

        Schema::create('guard_orders', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('guard_users');
        });
    });

    it('rejects a column that is an outbound foreign key on the model table', function () {
        $guard = new SchemaGuard(app('db'));

        try {
            $guard->assertSafe(new GuardOutboundFkSanitizer, GuardUser::class);

            $this->fail('Expected UnsafeColumnException to be thrown.');
        } catch (UnsafeColumnException $exception) {
            expect($exception->getMessage())->toContain(GuardUser::class);
            expect($exception->getMessage())->toContain('company_id');
            expect($exception->getMessage())->toContain('guard_users');
            expect($exception->getMessage())->toContain('guard_companies');
            expect($exception->getMessage())->toContain(GuardOutboundFkSanitizer::class);
        }
    });

    it('rejects a column that is referenced by another table\'s foreign key', function () {
        $guard = new SchemaGuard(app('db'));

        try {
            $guard->assertSafe(new GuardInboundRefSanitizer, GuardUser::class);

            $this->fail('Expected UnsafeColumnException to be thrown.');
        } catch (UnsafeColumnException $exception) {
            expect($exception->getMessage())->toContain(GuardUser::class);
            expect($exception->getMessage())->toContain('id');
            expect($exception->getMessage())->toContain('guard_orders');
        }
    });

    it('passes cleanly when no declared column is a foreign key or referenced', function () {
        $guard = new SchemaGuard(app('db'));

        $guard->assertSafe(new GuardCleanSanitizer, GuardUser::class);

        expect(true)->toBeTrue();
    });

    it('rejects a declared column that does not exist on the table', function () {
        $guard = new SchemaGuard(app('db'));

        try {
            $guard->assertSafe(new GuardUnknownColumnSanitizer, GuardUser::class);

            $this->fail('Expected UnsafeColumnException to be thrown.');
        } catch (UnsafeColumnException $exception) {
            expect($exception->getMessage())->toContain('emial');
            expect($exception->getMessage())->toContain('guard_users');
            expect($exception->getMessage())->toContain(GuardUser::class);
        }
    });

    it('performs no queries when fields() is empty', function () {
        $guard = new SchemaGuard(app('db'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $guard->assertSafe(new GuardEmptySanitizer, GuardUser::class);

        expect(DB::getQueryLog())->toBe([]);
    });

    it('accepts a model instance as well as a class-string', function () {
        $guard = new SchemaGuard(app('db'));

        $guard->assertSafe(new GuardCleanSanitizer, new GuardUser);

        expect(true)->toBeTrue();
    });

    it('memoizes introspection per table across repeated calls', function () {
        $guard = new SchemaGuard(app('db'));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $guard->assertSafe(new GuardCleanSanitizer, GuardUser::class);
        $countAfterFirst = count(DB::getQueryLog());

        $guard->assertSafe(new GuardCleanSanitizer, GuardUser::class);
        $countAfterSecond = count(DB::getQueryLog());

        expect($countAfterSecond)->toBe($countAfterFirst);
    });
}
