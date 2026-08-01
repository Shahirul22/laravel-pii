<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class PrefixedCompany extends Model
    {
        protected $table = 'prefixed_companies';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PrefixedUser extends Model
    {
        protected $table = 'prefixed_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PrefixedOrder extends Model
    {
        protected $table = 'prefixed_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PrefixedInboundRefSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['id' => 'randomNumber'];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\SchemaGuard;

    /**
     * Regression coverage for the confirmed silent-bypass bug: under a
     * configured table prefix, ensureInboundIndex() stripped the prefix
     * from the swept table's own physical name but never applied the same
     * stripping to the FK entry's `foreign_table` value before using it as
     * the inbound-index key. Because inboundReferencedColumns() looks the
     * index up by the model's unprefixed logical table name, the index key
     * (still prefixed) never matched the lookup key (unprefixed), and
     * assertSafe() silently never threw UnsafeColumnException::inboundReference
     * for any column, under any prefix configuration.
     */
    beforeEach(function () {
        DB::connection()->setTablePrefix('wp_');

        Schema::create('prefixed_companies', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('prefixed_users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('prefixed_companies');
        });

        Schema::create('prefixed_orders', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('prefixed_users');
        });
    });

    afterEach(function () {
        DB::connection()->setTablePrefix('');
    });

    it('rejects an inbound-referenced column under a configured table prefix', function () {
        // Sanity check: the tables were actually created with the "wp_"
        // prefix applied to their physical names.
        expect(Schema::hasTable('prefixed_users'))->toBeTrue();

        $guard = new SchemaGuard(app('db'));

        try {
            $guard->assertSafe(new PrefixedInboundRefSanitizer, PrefixedUser::class);

            $this->fail('Expected UnsafeColumnException to be thrown.');
        } catch (UnsafeColumnException $exception) {
            expect($exception->getMessage())->toContain(PrefixedUser::class);
            expect($exception->getMessage())->toContain('id');
            expect($exception->getMessage())->toContain('prefixed_orders');
        }
    });
}
