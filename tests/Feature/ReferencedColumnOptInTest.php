<?php

namespace {
    use Illuminate\Database\Connection;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\ForeignKeySuspender;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\RunReport;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class RefCustomer extends Model
    {
        protected $table = 'ref_customers';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefOrder extends Model
    {
        protected $table = 'ref_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefTicket extends Model
    {
        protected $table = 'ref_tickets';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefTicketAlias extends Model
    {
        protected $table = 'ref_tickets';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefBareCustomerAlias extends Model
    {
        protected $table = 'ref_bare_customers';

        protected $primaryKey = 'nric';

        protected $keyType = 'string';

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefAudit extends Model
    {
        protected $table = 'ref_audits';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefNaturalCustomer extends Model
    {
        protected $table = 'ref_natural_customers';

        protected $primaryKey = 'nric';

        protected $keyType = 'string';

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefNaturalOrder extends Model
    {
        protected $table = 'ref_natural_orders';

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefBareCustomer extends Model
    {
        protected $table = 'ref_bare_customers';

        protected $primaryKey = 'nric';

        protected $keyType = 'string';

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;
    }

    class RefNote extends Model
    {
        protected $table = 'ref_notes';

        protected $guarded = [];

        public $timestamps = false;
    }

    abstract class RefStaticSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return static::$fields;
        }

        public function mirrors(): array
        {
            return static::$mirrors;
        }
    }

    class RefCustomerSanitizer extends RefStaticSanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        /** @var array<string, list<string>> */
        public static array $mirrors = [];
    }

    class RefOrderSanitizer extends RefStaticSanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        /** @var array<string, list<string>> */
        public static array $mirrors = [];
    }

    class RefTicketSanitizer extends RefStaticSanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        /** @var array<string, list<string>> */
        public static array $mirrors = [];
    }

    class RefNaturalCustomerSanitizer extends RefStaticSanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        /** @var array<string, list<string>> */
        public static array $mirrors = [];
    }

    class RefNaturalOrderSanitizer extends RefStaticSanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        /** @var array<string, list<string>> */
        public static array $mirrors = [];
    }

    class RefBareCustomerSanitizer extends RefStaticSanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        /** @var array<string, list<string>> */
        public static array $mirrors = [];
    }

    class RefBareCustomerAliasSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['nric' => refK()];
        }

        public function mirrors(): array
        {
            return ['nric' => ['ref_tickets.holder_nric']];
        }
    }

    class RefTicketAliasSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['holder_nric' => refK()];
        }
    }

    class RefNoteSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['note' => fn () => new stdClass];
        }
    }

    function refK(): Keyed
    {
        return Keyed::pattern('ref-nric', '######-##-####');
    }

    function refReset(): void
    {
        RefCustomerSanitizer::$fields = ['nric' => refK()];
        RefCustomerSanitizer::$mirrors = ['nric' => ['ref_orders.customer_nric', 'ref_tickets.holder_nric']];
        RefOrderSanitizer::$fields = ['customer_nric' => refK()];
        RefOrderSanitizer::$mirrors = ['customer_nric' => ['ref_customers.nric']];
        RefTicketSanitizer::$fields = ['holder_nric' => refK()];
        RefTicketSanitizer::$mirrors = [];
        RefNaturalCustomerSanitizer::$fields = ['nric' => refK()];
        RefNaturalCustomerSanitizer::$mirrors = ['nric' => ['ref_natural_orders.customer_nric']];
        RefNaturalOrderSanitizer::$fields = ['customer_nric' => refK()];
        RefNaturalOrderSanitizer::$mirrors = ['customer_nric' => ['ref_natural_customers.nric']];
        RefBareCustomerSanitizer::$fields = ['nric' => refK()];
        RefBareCustomerSanitizer::$mirrors = ['nric' => ['ref_tickets.holder_nric']];
    }

    /** @return array<class-string<Model>, class-string<Sanitizer>> */
    function refHappyMap(): array
    {
        return [
            RefCustomer::class => RefCustomerSanitizer::class,
            RefOrder::class => RefOrderSanitizer::class,
            RefTicket::class => RefTicketSanitizer::class,
        ];
    }

    /**
     * @param  array<class-string<Model>, class-string<Sanitizer>>  $map
     * @param  list<class-string<Model>>|null  $models
     */
    function refConfigure(array $map, ?array $models = null): void
    {
        config()->set('pii.sanitizers', $map);
        config()->set('pii.models', $models ?? array_keys($map));
    }

    /**
     * @param  array<class-string<Model>, class-string<Sanitizer>>  $map
     */
    function refRun(array $map, bool $dryRun = false, ?Closure $onProgress = null): RunReport
    {
        refConfigure($map);

        return app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2, dryRun: $dryRun, onProgress: $onProgress));
    }

    function refFkOn(): int
    {
        return (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
    }

    function refEnforce(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');

        expect(refFkOn())->toBe(1);
    }

    /** @return list<string> */
    function refDataReads(): array
    {
        $reads = [];

        foreach (DB::getQueryLog() as $entry) {
            if (preg_match('/\bfrom\s+"ref_[a-z_]+"/i', $entry['query']) === 1) {
                $reads[] = $entry['query'];
            }
        }

        return $reads;
    }

    function refStartLog(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }

    /** @return list<string> */
    function refSuspendWrites(): array
    {
        $writes = [];

        foreach (DB::getQueryLog() as $entry) {
            if (stripos($entry['query'], 'foreign_keys = off') !== false) {
                $writes[] = $entry['query'];
            }
        }

        return $writes;
    }

    /**
     * @param  array<class-string<Model>, class-string<Sanitizer>>  $map
     * @return array{string, list<string>}
     */
    function refThrownBy(array $map): array
    {
        try {
            refRun($map);
        } catch (Throwable $e) {
            return [$e::class, [$e->getMessage()]];
        }

        return ['', []];
    }

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x02", 32));

        refReset();

        Schema::create('ref_customers', function ($table) {
            $table->id();
            $table->string('nric')->unique();
            $table->string('name')->nullable();
        });

        Schema::create('ref_orders', function ($table) {
            $table->id();
            $table->string('customer_nric')->nullable();
            $table->foreign('customer_nric')->references('nric')->on('ref_customers');
        });

        Schema::create('ref_tickets', function ($table) {
            $table->id();
            $table->string('holder_nric')->nullable();
        });

        Schema::create('ref_audits', function ($table) {
            $table->id();
            $table->string('nric_copy')->nullable();
        });

        Schema::create('ref_natural_customers', function ($table) {
            $table->string('nric')->primary();
            $table->integer('customer_no')->unique();
            $table->string('name')->nullable();
        });

        Schema::create('ref_natural_orders', function ($table) {
            $table->id();
            $table->string('customer_nric')->nullable();
            $table->foreign('customer_nric')->references('nric')->on('ref_natural_customers');
        });

        Schema::create('ref_bare_customers', function ($table) {
            $table->string('nric')->primary();
            $table->string('name')->nullable();
        });

        Schema::create('ref_notes', function ($table) {
            $table->id();
            $table->string('note')->nullable();
        });

        foreach (['C-1', 'C-2', 'C-3'] as $nric) {
            DB::table('ref_customers')->insert(['nric' => $nric]);
        }

        foreach (['C-1', 'C-1', 'C-2', null] as $nric) {
            DB::table('ref_orders')->insert(['customer_nric' => $nric]);
        }

        foreach (['C-1', 'C-3', 'X-9', null] as $nric) {
            DB::table('ref_tickets')->insert(['holder_nric' => $nric]);
        }

        foreach (['C-1', 'C-2'] as $nric) {
            DB::table('ref_audits')->insert(['nric_copy' => $nric]);
        }

        DB::table('ref_natural_customers')->insert([['nric' => 'N-1', 'customer_no' => 1], ['nric' => 'N-2', 'customer_no' => 2]]);

        foreach (['N-1', 'N-2', 'N-1'] as $nric) {
            DB::table('ref_natural_orders')->insert(['customer_nric' => $nric]);
        }

        DB::table('ref_notes')->insert(['note' => 'hello']);
    });

    it('rewrites a referenced column and every declared mirror consistently under enforced foreign keys', function (array $order) {
        $map = [];

        foreach ($order as $model) {
            $map[$model] = [
                RefCustomer::class => RefCustomerSanitizer::class,
                RefOrder::class => RefOrderSanitizer::class,
                RefTicket::class => RefTicketSanitizer::class,
            ][$model];
        }

        refEnforce();
        expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);

        $customersBefore = DB::table('ref_customers')->orderBy('id')->pluck('nric', 'id')->all();
        $ordersBefore = DB::table('ref_orders')->orderBy('id')->pluck('customer_nric', 'id')->all();
        $ticketsBefore = DB::table('ref_tickets')->orderBy('id')->pluck('holder_nric', 'id')->all();

        $report = refRun($map);

        expect($report->failed())->toBeFalse();
        expect($report->foreignKeysSuspended)->toBeTrue();

        $customersAfter = DB::table('ref_customers')->orderBy('id')->pluck('nric', 'id')->all();
        $replacement = [];

        foreach ($customersBefore as $id => $original) {
            expect($customersAfter[$id])->not->toBe($original);
            $replacement[$original] = $customersAfter[$id];
        }

        $ordersAfter = DB::table('ref_orders')->orderBy('id')->pluck('customer_nric', 'id')->all();

        foreach ($ordersBefore as $id => $original) {
            expect($ordersAfter[$id])->toBe($original === null ? null : $replacement[$original]);
        }

        $ticketsAfter = DB::table('ref_tickets')->orderBy('id')->pluck('holder_nric', 'id')->all();

        foreach ($ticketsBefore as $id => $original) {
            if ($original === null) {
                expect($ticketsAfter[$id])->toBeNull();
            } elseif ($original === 'X-9') {
                expect($ticketsAfter[$id])->not->toBe('X-9');
                expect($ticketsAfter[$id])->toMatch('/^\d{6}-\d{2}-\d{4}$/');
            } else {
                expect($ticketsAfter[$id])->toBe($replacement[$original]);
            }
        }

        expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
        expect(refFkOn())->toBe(1);
    })->with([
        'parents first' => [[RefCustomer::class, RefOrder::class, RefTicket::class]],
        'children first' => [[RefTicket::class, RefOrder::class, RefCustomer::class]],
    ]);

    it('opts in a natural primary key when the target has an alternate paging identity', function () {
        refEnforce();

        $before = DB::table('ref_natural_customers')->orderBy('customer_no')->pluck('nric', 'customer_no')->all();
        $ordersBefore = DB::table('ref_natural_orders')->orderBy('id')->pluck('customer_nric', 'id')->all();

        $report = refRun([
            RefNaturalCustomer::class => RefNaturalCustomerSanitizer::class,
            RefNaturalOrder::class => RefNaturalOrderSanitizer::class,
        ]);

        expect($report->failed())->toBeFalse();

        $after = DB::table('ref_natural_customers')->orderBy('customer_no')->pluck('nric', 'customer_no')->all();
        $replacement = [];

        foreach ($before as $customerNo => $original) {
            expect($after[$customerNo])->not->toBe($original);
            $replacement[$original] = $after[$customerNo];
        }

        $ordersAfter = DB::table('ref_natural_orders')->orderBy('id')->pluck('customer_nric', 'id')->all();

        foreach ($ordersBefore as $id => $original) {
            expect($ordersAfter[$id])->toBe($replacement[$original]);
        }

        expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    });

    it('fails an opted-in primary key that has no alternate identity', function () {
        [$class, $messages] = refThrownBy([
            RefBareCustomer::class => RefBareCustomerSanitizer::class,
            RefTicket::class => RefTicketSanitizer::class,
        ]);

        expect($class)->toBe(UnpageableTableException::class);
        expect($messages[0])->toBe(UnpageableTableException::optedInPrimaryKey(RefBareCustomer::class, 'ref_bare_customers', RefBareCustomerSanitizer::class, 'nric')->getMessage());
    });

    it('keeps the v1 rejection without the opt-in and reads no row', function (string $case, string $needle) {
        $map = refHappyMap();

        if ($case === 'order') {
            RefOrderSanitizer::$mirrors = [];
            $map = [RefOrder::class => RefOrderSanitizer::class] + $map;
        } elseif ($case === 'customer') {
            RefCustomerSanitizer::$mirrors = [];
        } else {
            RefBareCustomerSanitizer::$mirrors = [];
            $map = [RefBareCustomer::class => RefBareCustomerSanitizer::class, RefTicket::class => RefTicketSanitizer::class];
        }

        refStartLog();

        [$class, $messages] = refThrownBy($map);

        expect($class)->toBe(UnsafeColumnException::class);
        expect($messages[0])->toContain($needle);
        expect(refDataReads())->toBe([]);
    })->with([
        'outbound foreign key' => ['order', 'is a foreign key'],
        'inbound reference' => ['customer', 'is referenced by a foreign key'],
        'primary key' => ['bare', 'primary key'],
    ]);

    it('check 1: rejects a malformed mirrors() entry before any row is read', function () {
        RefCustomerSanitizer::$mirrors = ['nric' => ['ref_orders']];

        refStartLog();

        [$class, $messages] = refThrownBy(refHappyMap());

        expect($class)->toBe(InvalidConfigurationException::class);
        expect($messages[0])->toBe(InvalidConfigurationException::invalidMirrorDeclaration(RefCustomerSanitizer::class, 'nric', 'mirror "ref_orders" is not of the form table.column')->getMessage());
        expect(refDataReads())->toBe([]);
    });

    it('check 2: rejects a mirrors() key that is not Keyed before any row is read', function () {
        RefCustomerSanitizer::$fields = ['nric' => 'name'];

        refStartLog();

        [$class, $messages] = refThrownBy(refHappyMap());

        expect($class)->toBe(InvalidConfigurationException::class);
        expect($messages[0])->toBe(InvalidConfigurationException::mirroredColumnNotKeyed(RefCustomer::class, 'nric', RefCustomerSanitizer::class)->getMessage());
        expect(refDataReads())->toBe([]);
    });

    it('check 3: an entry in another sanitizer never relaxes this column', function () {
        RefOrderSanitizer::$mirrors = [];

        refStartLog();

        [$class, $messages] = refThrownBy(refHappyMap());

        expect($class)->toBe(UnsafeColumnException::class);
        expect($messages[0])->toContain('is a foreign key');
        expect($messages[0])->toContain('customer_nric');
        expect(refDataReads())->toBe([]);
    });

    it('check 4: rejects a mirror that no target of the run declares', function () {
        refConfigure(refHappyMap(), [RefCustomer::class, RefTicket::class]);

        refStartLog();

        try {
            app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));
            $this->fail('Expected InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            expect($e->getMessage())->toBe(InvalidConfigurationException::mirrorNotInRun(RefCustomerSanitizer::class, 'nric', 'ref_orders.customer_nric')->getMessage());
        }

        expect(refDataReads())->toBe([]);
    });

    it('check 4: rejects a mirror excluded with --model', function () {
        refConfigure(refHappyMap());

        refStartLog();

        try {
            app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2, models: [RefCustomer::class, RefTicket::class]));
            $this->fail('Expected InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            expect($e->getMessage())->toBe(InvalidConfigurationException::mirrorNotInRun(RefCustomerSanitizer::class, 'nric', 'ref_orders.customer_nric')->getMessage());
        }

        expect(refDataReads())->toBe([]);
    });

    it('check 4: rejects a mirror whose target has no sanitizer', function () {
        $map = refHappyMap();
        unset($map[RefOrder::class]);

        refConfigure($map, [RefCustomer::class, RefOrder::class, RefTicket::class]);

        refStartLog();

        try {
            app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));
            $this->fail('Expected InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            expect($e->getMessage())->toBe(InvalidConfigurationException::mirrorNotInRun(RefCustomerSanitizer::class, 'nric', 'ref_orders.customer_nric')->getMessage());
        }

        expect(refDataReads())->toBe([]);
    });

    it('check 4: rejects a mirror declared by more than one target', function () {
        $map = refHappyMap() + [RefTicketAlias::class => RefTicketAliasSanitizer::class];

        refStartLog();

        [$class, $messages] = refThrownBy($map);

        expect($class)->toBe(InvalidConfigurationException::class);
        expect($messages[0])->toBe(InvalidConfigurationException::mirrorAmbiguous('ref_tickets.holder_nric')->getMessage());
        expect(refDataReads())->toBe([]);
    });

    it('check 4: rejects a mirrors() source column declared by more than one target (BUG-32)', function () {
        // No target lists ref_bare_customers.nric as a mirror, so only the
        // source-side check can catch the second declaration.
        $map = [
            RefBareCustomer::class => RefBareCustomerSanitizer::class,
            RefBareCustomerAlias::class => RefBareCustomerAliasSanitizer::class,
            RefTicket::class => RefTicketSanitizer::class,
        ];

        refStartLog();

        [$class, $messages] = refThrownBy($map);

        expect($class)->toBe(InvalidConfigurationException::class);
        expect($messages[0])->toBe(InvalidConfigurationException::mirrorAmbiguous('ref_bare_customers.nric')->getMessage());
        expect(refDataReads())->toBe([]);
    });

    it('check 5: rejects mirrors that use different Keyed namespaces', function () {
        RefTicketSanitizer::$fields = ['holder_nric' => Keyed::pattern('other-ns', '######-##-####')];

        refStartLog();

        [$class, $messages] = refThrownBy(refHappyMap());

        expect($class)->toBe(InvalidConfigurationException::class);
        expect($messages[0])->toBe(InvalidConfigurationException::mirrorNamespaceMismatch('ref_customers.nric', 'ref_tickets.holder_nric', 'ref-nric', 'other-ns')->getMessage());
        expect(refDataReads())->toBe([]);
    });

    it('check 6: refuses an undeclared foreign-key child and never adds it (no inference)', function () {
        Schema::create('ref_payments', function ($table) {
            $table->id();
            $table->string('customer_nric')->nullable();
            $table->foreign('customer_nric')->references('nric')->on('ref_customers');
        });

        DB::table('ref_payments')->insert(['customer_nric' => 'C-1']);

        refStartLog();

        [$class, $messages] = refThrownBy(refHappyMap());

        expect($class)->toBe(UnsafeColumnException::class);
        expect($messages[0])->toContain('the foreign key ref_payments.customer_nric -> ref_customers.nric links it to a column that is not declared as a mirror');
        expect(refDataReads())->toBe([]);
        expect(DB::table('ref_payments')->pluck('customer_nric')->all())->toBe(['C-1']);
    });

    it('check 7: refuses when SQLite cannot suspend inside an open transaction', function () {
        refEnforce();
        DB::beginTransaction();

        try {
            refStartLog();

            [$class, $messages] = refThrownBy(refHappyMap());

            expect($class)->toBe(UnsafeColumnException::class);
            expect($messages[0])->toBe('[laravel-pii-sanitizer] Opted-in referenced columns need foreign-key enforcement suspended for this run, which is not possible here: the SQLite connection is inside an open transaction, where PRAGMA foreign_keys cannot change.');
            expect(refDataReads())->toBe([]);
        } finally {
            DB::rollBack();
        }
    });

    it('writes only the declared columns and leaves an undeclared logical copy untouched', function () {
        refStartLog();

        $report = refRun(refHappyMap());

        expect($report->failed())->toBeFalse();

        $updated = [];

        foreach (DB::getQueryLog() as $entry) {
            if (preg_match('/^update\s+"([a-z_]+)"/i', $entry['query'], $match) === 1) {
                $updated[$match[1]] = true;
            }
        }

        expect(array_keys($updated))->toEqualCanonicalizing(['ref_customers', 'ref_orders', 'ref_tickets']);
        expect(DB::table('ref_audits')->orderBy('id')->pluck('nric_copy')->all())->toBe(['C-1', 'C-2']);
    });

    it('restores enforcement after a failing run', function () {
        refEnforce();

        $report = refRun(refHappyMap() + [RefNote::class => RefNoteSanitizer::class]);

        expect($report->failed())->toBeTrue();
        expect($report->foreignKeysSuspended)->toBeTrue();
        expect(refFkOn())->toBe(1);
    });

    it('restores enforcement after an exception escapes the run', function () {
        refEnforce();

        expect(fn () => refRun(refHappyMap(), onProgress: fn () => throw new LogicException('stop')))
            ->toThrow(LogicException::class, 'stop');

        expect(refFkOn())->toBe(1);
    });

    it('never swallows a restore failure and chains the in-flight exception', function () {
        app()->instance(ForeignKeySuspender::class, new class extends ForeignKeySuspender
        {
            public function assertSuspendable(Connection $connection): void {}

            public function suspend(Connection $connection): ?Closure
            {
                return fn () => throw new RuntimeException('restore failed');
            }
        });

        expect(fn () => refRun(refHappyMap()))->toThrow(RuntimeException::class, 'restore failed');

        try {
            refRun(refHappyMap(), onProgress: fn () => throw new LogicException('stop'));
            $this->fail('Expected the restore failure.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('restore failed');
            expect($e->getPrevious())->toBeInstanceOf(LogicException::class);
        }
    });

    it('reports no suspension when enforcement is already off', function () {
        expect(refFkOn())->toBe(0);

        refStartLog();

        $report = refRun(refHappyMap());

        expect($report->failed())->toBeFalse();
        expect($report->foreignKeysSuspended)->toBeFalse();
        expect(refSuspendWrites())->toBe([]);
    });

    it('never suspends on a dry run', function () {
        refEnforce();

        $before = DB::table('ref_customers')->orderBy('id')->pluck('nric')->all();

        refStartLog();

        $report = refRun(refHappyMap(), dryRun: true);

        expect($report->failed())->toBeFalse();
        expect($report->foreignKeysSuspended)->toBeFalse();
        expect(DB::table('ref_customers')->orderBy('id')->pluck('nric')->all())->toBe($before);
        expect(refFkOn())->toBe(1);
        expect(refSuspendWrites())->toBe([]);
    });

    it('prints the suspension notice on a successful run', function () {
        refEnforce();
        refConfigure(refHappyMap());

        $this->artisan('pii:sanitize')
            ->expectsOutputToContain('Foreign-key enforcement was suspended for this run because opted-in referenced columns were rewritten.')
            ->assertExitCode(0);
    });

    it('names ordinary triggers in the suspension notice on PostgreSQL only', function () {
        app()->instance(ForeignKeySuspender::class, new class extends ForeignKeySuspender
        {
            public function suspendsTriggers(Connection $connection): bool
            {
                return true;
            }
        });

        refEnforce();
        refConfigure(refHappyMap());

        $this->artisan('pii:sanitize')
            ->expectsOutputToContain('Foreign-key enforcement and ordinary triggers were suspended for this run because opted-in referenced columns were rewritten.')
            ->doesntExpectOutputToContain('Foreign-key enforcement was suspended for this run')
            ->assertExitCode(0);
    });

    it('keeps the foreign-key-only notice when the driver does not suspend triggers', function () {
        app()->instance(ForeignKeySuspender::class, new class extends ForeignKeySuspender
        {
            public function suspendsTriggers(Connection $connection): bool
            {
                return false;
            }
        });

        refEnforce();
        refConfigure(refHappyMap());

        $this->artisan('pii:sanitize')
            ->expectsOutputToContain('Foreign-key enforcement was suspended for this run because opted-in referenced columns were rewritten.')
            ->doesntExpectOutputToContain('ordinary triggers')
            ->assertExitCode(0);
    });

    it('prints the notice and the warning on a failing run', function () {
        refEnforce();
        refConfigure(refHappyMap() + [RefNote::class => RefNoteSanitizer::class]);

        $this->artisan('pii:sanitize')
            ->expectsOutputToContain('Foreign-key enforcement was suspended for this run because opted-in referenced columns were rewritten.')
            ->expectsOutputToContain('Foreign-key enforcement was suspended and the run stopped part-way, so referenced columns and their mirrors may now disagree. Restore the database dump and run again: re-running on partly sanitized data does not repair them.')
            ->assertExitCode(1);
    });

    it('prints no suspension line when no group exists', function () {
        refEnforce();
        refConfigure([RefTicket::class => RefTicketSanitizer::class]);

        $this->artisan('pii:sanitize')
            ->doesntExpectOutputToContain('Foreign-key enforcement was suspended')
            ->assertExitCode(0);
    });

    it('runs a Keyed unique column and its mirror group twice without reseeding, staying unique and consistent (BUG-22)', function () {
        refEnforce();

        $runs = [];

        foreach ([1, 2] as $run) {
            $report = refRun(refHappyMap());

            expect($report->failed())->toBeFalse();
            expect($report->foreignKeysSuspended)->toBeTrue();

            $customers = DB::table('ref_customers')->orderBy('id')->pluck('nric', 'id')->all();
            $orders = DB::table('ref_orders')->orderBy('id')->pluck('customer_nric', 'id')->all();
            $tickets = DB::table('ref_tickets')->orderBy('id')->pluck('holder_nric', 'id')->all();

            expect(array_unique($customers))->toHaveCount(3);

            foreach ($customers as $nric) {
                expect($nric)->toMatch('/^\d{6}-\d{2}-\d{4}$/');
            }

            // Orders hold C-1, C-1, C-2, NULL; tickets hold C-1, C-3, X-9, NULL.
            expect($orders)->toBe([1 => $customers[1], 2 => $customers[1], 3 => $customers[2], 4 => null]);
            expect([$tickets[1], $tickets[2], $tickets[4]])->toBe([$customers[1], $customers[3], null]);
            expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
            expect(refFkOn())->toBe(1);

            $runs[$run] = $customers;
        }

        foreach ($runs[2] as $id => $nric) {
            expect($nric)->not->toBe($runs[1][$id]);
        }
    });
}
