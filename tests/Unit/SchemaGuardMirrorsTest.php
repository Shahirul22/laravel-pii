<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\SchemaGuard;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class SgmCustomer extends Model
    {
        protected $table = 'sgm_customers';

        protected $guarded = [];

        public $timestamps = false;
    }

    class SgmOrder extends Model
    {
        protected $table = 'sgm_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class SgmBare extends Model
    {
        protected $table = 'sgm_bare';

        protected $primaryKey = 'nric';

        protected $keyType = 'string';

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $mirrors
     */
    function sgmSanitizer(array $fields, array $mirrors): Sanitizer
    {
        return new class($fields, $mirrors) extends Sanitizer
        {
            /**
             * @param  array<string, mixed>  $f
             * @param  array<string, list<string>>  $m
             */
            public function __construct(private array $f, private array $m) {}

            public function fields(): array
            {
                return $this->f;
            }

            public function mirrors(): array
            {
                return $this->m;
            }
        };
    }

    function sgmKeyed(): Keyed
    {
        return Keyed::pattern('sgm', '######');
    }

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x03", 32));

        Schema::create('sgm_products', function ($table) {
            $table->id();
        });

        Schema::create('sgm_customers', function ($table) {
            $table->id();
            $table->string('nric')->unique();
            $table->string('name')->nullable();
        });

        Schema::create('sgm_orders', function ($table) {
            $table->id();
            $table->string('customer_nric')->nullable();
            $table->foreign('customer_nric')->references('nric')->on('sgm_customers');
            $table->foreignId('product_id')->nullable()->constrained('sgm_products');
        });

        Schema::create('sgm_bare', function ($table) {
            $table->string('nric')->primary();
            $table->string('name')->nullable();
        });
    });

    it('rejects an invalid mirrors() declaration with the exact message', function (bool $declared, array $mirrors, string $column, string $reason) {
        $sanitizer = sgmSanitizer($declared ? ['nric' => sgmKeyed()] : [], $mirrors);

        expect(fn () => app(SchemaGuard::class)->assertSafe($sanitizer, SgmCustomer::class))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::invalidMirrorDeclaration($sanitizer::class, $column, $reason)->getMessage());
    })->with([
        'key not in fields' => [true, ['email' => ['sgm_orders.customer_nric']], 'email', 'the column is not declared in fields()'],
        'empty list' => [true, ['nric' => []], 'nric', 'the mirror list is empty'],
        'no dot' => [true, ['nric' => ['sgm_orders']], 'nric', 'mirror "sgm_orders" is not of the form table.column'],
        'empty table' => [true, ['nric' => ['.customer_nric']], 'nric', 'mirror ".customer_nric" is not of the form table.column'],
        'empty column' => [true, ['nric' => ['sgm_orders.']], 'nric', 'mirror "sgm_orders." is not of the form table.column'],
        'names itself' => [true, ['nric' => ['sgm_customers.nric']], 'nric', 'mirror "sgm_customers.nric" names the column itself'],
        'list is a string (BUG-18)' => [true, ['nric' => 'sgm_orders.customer_nric'], 'nric', 'the mirror list is a string, not a list of "table.column" strings'],
        'entry is an int (BUG-18)' => [true, ['nric' => [5]], 'nric', 'a mirror entry is an int, not a "table.column" string'],
        'entry is an array (BUG-18)' => [true, ['nric' => [['sgm_orders.customer_nric']]], 'nric', 'a mirror entry is an array, not a "table.column" string'],
    ]);

    it('says mirrors() must be a map when it is declared as a list', function () {
        $sanitizer = sgmSanitizer(['nric' => sgmKeyed()], ['sgm_orders.customer_nric']);

        try {
            app(SchemaGuard::class)->assertSafe($sanitizer, SgmCustomer::class);

            $this->fail('Expected InvalidConfigurationException to be thrown.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toBe('[laravel-pii-sanitizer] '.$sanitizer::class.'::mirrors() entry 0 is invalid: it has no column name. mirrors() must be a map of column => list of "table.column" strings, for example [\'nric\' => [\'orders.customer_nric\']].');
            expect($exception->getMessage())->not->toContain('$0');
        }
    });

    it('gives a mirrors-only sanitizer with an empty fields() the empty-fields error (BUG-9)', function () {
        $sanitizer = sgmSanitizer([], ['nric' => ['sgm_orders.customer_nric']]);

        expect(fn () => app(SchemaGuard::class)->assertSafe($sanitizer, SgmCustomer::class))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::emptyFields($sanitizer::class, SgmCustomer::class, 'sgm_customers')->getMessage());
    });

    it('rejects a mirrors() key that is not a Keyed definition', function () {
        $sanitizer = sgmSanitizer(['nric' => 'name'], ['nric' => ['sgm_orders.customer_nric']]);

        expect(fn () => app(SchemaGuard::class)->assertSafe($sanitizer, SgmCustomer::class))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::mirroredColumnNotKeyed(SgmCustomer::class, 'nric', $sanitizer::class)->getMessage());
    });

    it('skips the inbound-reference rejection for an opted-in column', function () {
        $sanitizer = sgmSanitizer(['nric' => sgmKeyed()], ['nric' => ['sgm_orders.customer_nric']]);

        app(SchemaGuard::class)->assertSafe($sanitizer, SgmCustomer::class);

        expect(true)->toBeTrue();
    });

    it('skips the outbound-FK rejection for an opted-in column', function () {
        $sanitizer = sgmSanitizer(['customer_nric' => sgmKeyed()], ['customer_nric' => ['sgm_customers.nric']]);

        app(SchemaGuard::class)->assertSafe($sanitizer, SgmOrder::class);

        expect(true)->toBeTrue();
    });

    it('skips the primary-key rejection for an opted-in column', function () {
        $sanitizer = sgmSanitizer(['nric' => sgmKeyed()], ['nric' => ['sgm_customers.nric']]);

        app(SchemaGuard::class)->assertSafe($sanitizer, SgmBare::class);

        expect(true)->toBeTrue();
    });

    it('keeps the opt-in per column: a sibling FK column is still rejected', function () {
        $sanitizer = sgmSanitizer(
            ['customer_nric' => sgmKeyed(), 'product_id' => 'randomNumber'],
            ['customer_nric' => ['sgm_customers.nric']]
        );

        expect(fn () => app(SchemaGuard::class)->assertSafe($sanitizer, SgmOrder::class))
            ->toThrow(UnsafeColumnException::class, 'product_id');
    });

    it('keeps the v1 rejection when mirrors() is empty', function (string $model, string $column, string $needle, bool $remedy) {
        $sanitizer = sgmSanitizer([$column => sgmKeyed()], []);

        try {
            app(SchemaGuard::class)->assertSafe($sanitizer, $model);
            $this->fail('Expected UnsafeColumnException.');
        } catch (UnsafeColumnException $e) {
            expect($e->getMessage())->toContain($needle);

            $sentence = 'To sanitize it consistently with the columns that hold the same value, declare it in mirrors() with a Keyed definition.';

            if ($remedy) {
                expect($e->getMessage())->toContain($sentence);
            } else {
                expect($e->getMessage())->not->toContain($sentence);
            }
        }
    })->with([
        'outbound' => [SgmOrder::class, 'customer_nric', 'is a foreign key', true],
        'inbound' => [SgmCustomer::class, 'nric', 'is referenced by a foreign key', true],
        'primary key' => [SgmBare::class, 'nric', 'is the primary key', false],
    ]);

    it('checks mirrors() declarations without running any query', function () {
        $sanitizer = sgmSanitizer(['nric' => sgmKeyed()], ['nric' => ['sgm_orders']]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(SchemaGuard::class)->assertSafe($sanitizer, SgmCustomer::class);
        } catch (InvalidConfigurationException) {
            // expected
        }

        expect(DB::getQueryLog())->toBe([]);
    });
}
