<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\ReferencedColumnGuard;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class RcgCustomer extends Model
    {
        protected $table = 'rcg_customers';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RcgOrder extends Model
    {
        protected $table = 'rcg_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RcgTicket extends Model
    {
        protected $table = 'rcg_tickets';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RcgEmployee extends Model
    {
        protected $table = 'rcg_employees';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RcgChild extends Model
    {
        protected $table = 'rcg_children';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RcgPlainCustomer extends Model
    {
        protected $table = 'rcg_plain_customers';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RcgSanitizer extends Sanitizer
    {
        /**
         * @param  array<string, mixed>  $fields
         * @param  array<string, list<string>>  $mirrors
         */
        public function __construct(private array $fields = [], private array $mirrors = []) {}

        public function fields(): array
        {
            return $this->fields;
        }

        public function mirrors(): array
        {
            return $this->mirrors;
        }
    }

    function rcgK(string $namespace = 'rcg'): Keyed
    {
        return Keyed::pattern($namespace, '######');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, list<string>>  $mirrors
     * @return array{label: string, model: Model, sanitizer: ?Sanitizer}
     */
    function rcgTarget(string $model, ?array $fields = null, array $mirrors = []): array
    {
        $instance = new $model;

        return [
            'label' => $model,
            'model' => $instance,
            'sanitizer' => $fields === null ? null : new RcgSanitizer($fields, $mirrors),
        ];
    }

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x04", 32));

        Schema::create('rcg_customers', function ($table) {
            $table->id();
            $table->string('nric')->unique();
        });

        Schema::create('rcg_orders', function ($table) {
            $table->id();
            $table->string('customer_nric')->nullable();
            $table->foreign('customer_nric')->references('nric')->on('rcg_customers');
        });

        Schema::create('rcg_tickets', function ($table) {
            $table->id();
            $table->string('holder_nric')->nullable();
        });

        Schema::create('rcg_plain_customers', function ($table) {
            $table->id();
            $table->string('nric')->unique();
        });

        Schema::create('rcg_employees', function ($table) {
            $table->id();
            $table->string('staff_id')->unique();
            $table->string('manager_staff_id')->nullable();
            $table->foreign('manager_staff_id')->references('staff_id')->on('rcg_employees');
        });

        Schema::create('rcg_parents', function ($table) {
            $table->id();
            $table->string('x');
            $table->string('y');
            $table->unique(['x', 'y']);
        });

        Schema::create('rcg_children', function ($table) {
            $table->id();
            $table->string('a');
            $table->string('b');
            $table->foreign(['a', 'b'])->references(['x', 'y'])->on('rcg_parents');
        });
    });

    it('returns an empty list and runs no query when no target declares mirrors', function () {
        $targets = [
            rcgTarget(RcgCustomer::class, ['nric' => rcgK()]),
            rcgTarget(RcgOrder::class, ['customer_nric' => rcgK()]),
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();

        expect(app(ReferencedColumnGuard::class)->assertGroups($targets))->toBe([]);
        expect(DB::getQueryLog())->toBe([]);
    });

    it('returns no connection for a group without a foreign-key edge', function () {
        $targets = [
            rcgTarget(RcgPlainCustomer::class, ['nric' => rcgK()], ['nric' => ['rcg_tickets.holder_nric']]),
            rcgTarget(RcgTicket::class, ['holder_nric' => rcgK()]),
        ];

        expect(app(ReferencedColumnGuard::class)->assertGroups($targets))->toBe([]);
    });

    it('returns the connection once for groups with a foreign-key edge', function () {
        $targets = [
            rcgTarget(RcgCustomer::class, ['nric' => rcgK()], ['nric' => ['rcg_orders.customer_nric', 'rcg_tickets.holder_nric']]),
            rcgTarget(RcgOrder::class, ['customer_nric' => rcgK()], ['customer_nric' => ['rcg_customers.nric']]),
            rcgTarget(RcgTicket::class, ['holder_nric' => rcgK()]),
            rcgTarget(RcgEmployee::class, ['staff_id' => rcgK('emp'), 'manager_staff_id' => rcgK('emp')], [
                'staff_id' => ['rcg_employees.manager_staff_id'],
                'manager_staff_id' => ['rcg_employees.staff_id'],
            ]),
        ];

        $connections = app(ReferencedColumnGuard::class)->assertGroups($targets);

        expect($connections)->toHaveCount(1);
        expect($connections[0]->getName())->toBe(DB::connection()->getName());
    });

    it('pairs a composite foreign key by position and names only the touched pair', function () {
        $targets = [
            rcgTarget(RcgChild::class, ['a' => rcgK()], ['a' => ['rcg_tickets.holder_nric']]),
            rcgTarget(RcgTicket::class, ['holder_nric' => rcgK()]),
        ];

        try {
            app(ReferencedColumnGuard::class)->assertGroups($targets);
            $this->fail('Expected UnsafeColumnException.');
        } catch (UnsafeColumnException $e) {
            expect($e->getMessage())->toContain('rcg_children.a -> rcg_parents.x');
            expect($e->getMessage())->not->toContain('rcg_children.b');
        }
    });

    it('includes a same-table edge in the foreign-key closure', function () {
        $targets = [
            rcgTarget(RcgEmployee::class, ['staff_id' => rcgK()], ['staff_id' => ['rcg_tickets.holder_nric']]),
            rcgTarget(RcgTicket::class, ['holder_nric' => rcgK()]),
        ];

        expect(fn () => app(ReferencedColumnGuard::class)->assertGroups($targets))
            ->toThrow(UnsafeColumnException::class, 'rcg_employees.manager_staff_id -> rcg_employees.staff_id');
    });

    it('fails when a mirror points at a target with no sanitizer', function () {
        $targets = [
            rcgTarget(RcgCustomer::class, ['nric' => rcgK()], ['nric' => ['rcg_orders.customer_nric']]),
            rcgTarget(RcgOrder::class),
        ];

        expect(fn () => app(ReferencedColumnGuard::class)->assertGroups($targets))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::mirrorNotInRun(RcgSanitizer::class, 'nric', 'rcg_orders.customer_nric')->getMessage());
    });

    it('fails when a listed member is not a Keyed definition', function () {
        $targets = [
            rcgTarget(RcgCustomer::class, ['nric' => rcgK()], ['nric' => ['rcg_tickets.holder_nric']]),
            rcgTarget(RcgTicket::class, ['holder_nric' => 'name']),
        ];

        expect(fn () => app(ReferencedColumnGuard::class)->assertGroups($targets))
            ->toThrow(InvalidConfigurationException::class, InvalidConfigurationException::mirroredColumnNotKeyed(RcgTicket::class, 'holder_nric', RcgSanitizer::class)->getMessage());
    });
}
