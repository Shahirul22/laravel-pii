<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Schema\SQLiteBuilder;
    use Illuminate\Database\SQLiteConnection;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class IfkCustomer extends Model
    {
        protected $table = 'ifk_customers';

        protected $guarded = [];

        public $timestamps = false;
    }

    class IfkOrder extends Model
    {
        protected $table = 'ifk_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class IfkMalformedParent extends Model
    {
        protected $connection = 'ifk_malformed';

        protected $table = 'ifk_parents';

        protected $guarded = [];

        public $timestamps = false;
    }

    class IfkMalformedChild extends Model
    {
        protected $connection = 'ifk_malformed';

        protected $table = 'ifk_children';

        protected $guarded = [];

        public $timestamps = false;
    }

    class IfkSanitizer extends Sanitizer
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

    /**
     * Reports a foreign key whose referenced-column list is shorter than its
     * column list, which no supported driver produces but which the guard
     * must still refuse with a named error rather than an undefined offset.
     */
    class IfkMalformedBuilder extends SQLiteBuilder
    {
        public function getForeignKeys($table)
        {
            if (str_ends_with($table, 'ifk_children')) {
                return [[
                    'name' => null,
                    'columns' => ['a', 'b'],
                    'foreign_schema' => 'main',
                    'foreign_table' => 'ifk_parents',
                    'foreign_columns' => ['x'],
                    'on_update' => 'no action',
                    'on_delete' => 'no action',
                ]];
            }

            return [];
        }
    }

    class IfkMalformedConnection extends SQLiteConnection
    {
        public function getSchemaBuilder()
        {
            if (is_null($this->schemaGrammar)) {
                $this->useDefaultSchemaGrammar();
            }

            return new IfkMalformedBuilder($this);
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\ReferencedColumnGuard;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x05", 32));
    });

    it('refuses a mirror group that leaves out a column linked by an implicit SQLite foreign key (BUG-17)', function () {
        DB::statement('create table "ifk_customers" ("id" integer, "nric" varchar primary key)');
        DB::statement('create table "ifk_orders" ("id" integer primary key, "cust" varchar references "ifk_customers")');

        // ifk_customers.nric is mirrored with nothing; ifk_orders.cust is linked to it by the implicit key.
        $targets = [
            ['label' => IfkCustomer::class, 'model' => new IfkCustomer, 'sanitizer' => new IfkSanitizer(['nric' => Keyed::pattern('ifk', '######')], ['nric' => []])],
        ];

        expect(fn () => app(ReferencedColumnGuard::class)->assertGroups($targets))
            ->toThrow(UnsafeColumnException::class, 'ifk_orders.cust -> ifk_customers.nric');
    });

    it('refuses a foreign key whose column lists differ in length with a named error (BUG-17)', function () {
        DB::extend('ifk_malformed', fn (array $config, string $name) => new IfkMalformedConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));
        config()->set('database.connections.ifk_malformed', ['driver' => 'ifk_malformed', 'database' => ':memory:', 'prefix' => '']);

        Schema::connection('ifk_malformed')->create('ifk_parents', function ($table) {
            $table->id();
            $table->string('x');
            $table->string('y');
        });

        Schema::connection('ifk_malformed')->create('ifk_children', function ($table) {
            $table->id();
            $table->string('a');
            $table->string('b');
        });

        $keyed = Keyed::pattern('ifk', '######');

        $targets = [
            ['label' => IfkMalformedChild::class, 'model' => new IfkMalformedChild, 'sanitizer' => new IfkSanitizer(['a' => $keyed], ['a' => ['ifk_parents.x']])],
            ['label' => IfkMalformedParent::class, 'model' => new IfkMalformedParent, 'sanitizer' => new IfkSanitizer(['x' => $keyed])],
        ];

        expect(fn () => app(ReferencedColumnGuard::class)->assertGroups($targets))
            ->toThrow(UnsafeColumnException::class, UnsafeColumnException::malformedForeignKey('ifk_children', ['a', 'b'], 'ifk_parents', ['x'])->getMessage());
    });
}
