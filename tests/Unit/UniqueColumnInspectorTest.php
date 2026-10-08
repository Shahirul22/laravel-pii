<?php

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\UniqueColumnInspector;

    beforeEach(function () {
        Schema::create('uci_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('nickname')->nullable();
        });

        Schema::create('uci_pairs', function ($table) {
            $table->id();
            $table->string('tenant');
            $table->string('slug');
            $table->unique(['tenant', 'slug']);
        });

        Schema::create('uci_plain', function ($table) {
            $table->id();
        });
    });

    it('reports a single-column unique constraint as a one-element tuple', function () {
        $inspector = new UniqueColumnInspector(app('db'));

        $constraints = $inspector->uniqueConstraints('uci_users');

        expect($constraints)->toContain(['email']);
    });

    it('reports a composite-unique constraint as one ordered tuple, not two single-column entries', function () {
        $inspector = new UniqueColumnInspector(app('db'));

        $constraints = $inspector->uniqueConstraints('uci_pairs');

        expect($constraints)->toContain(['tenant', 'slug']);

        foreach ($constraints as $tuple) {
            expect($tuple === ['tenant'] || $tuple === ['slug'])->toBeFalse();
        }
    });

    it('reports the primary key column as a tuple', function () {
        $inspector = new UniqueColumnInspector(app('db'));

        $constraints = $inspector->uniqueConstraints('uci_users');

        expect($constraints)->toContain(['id']);
    });

    it('de-duplicates tuples so no tuple value appears twice', function () {
        $inspector = new UniqueColumnInspector(app('db'));

        $constraints = $inspector->uniqueConstraints('uci_users');

        $keys = array_map(fn (array $tuple) => implode("\x1f", $tuple), $constraints);

        expect(array_unique($keys))->toHaveCount(count($keys));
    });

    it('returns only constraints affecting the declared columns via constraintsAffecting()', function () {
        $inspector = new UniqueColumnInspector(app('db'));

        expect($inspector->constraintsAffecting('uci_pairs', ['slug']))->toContain(['tenant', 'slug']);
        expect($inspector->constraintsAffecting('uci_users', ['nickname']))->toBe([]);
    });

    it('memoizes per table so a second call issues zero further queries', function () {
        $inspector = new UniqueColumnInspector(app('db'));

        $inspector->uniqueConstraints('uci_users');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $inspector->uniqueConstraints('uci_users');

        expect(DB::getQueryLog())->toBe([]);
    });

    it('reads constraints from the given connection, not the default connection', function () {
        config()->set('database.connections.uci_secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Same table name on both connections, but only the secondary
        // connection's copy carries a unique constraint on 'code' — a
        // pre-fix implementation (always reading the default connection)
        // would report zero constraints here, passing this assertion
        // vacuously the way a same-shape cross-connection test would.
        Schema::create('uci_divergent', function ($table) {
            $table->id();
            $table->string('code')->nullable();
        });

        Schema::connection('uci_secondary')->create('uci_divergent', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });

        $inspector = new UniqueColumnInspector(app('db'));

        expect($inspector->uniqueConstraints('uci_divergent'))->not->toContain(['code']);
        expect($inspector->uniqueConstraints('uci_divergent', 'uci_secondary'))->toContain(['code']);
    });

    it('reports a single-column primary key via primaryKey()', function () {
        $inspector = new UniqueColumnInspector(app('db'));

        expect($inspector->primaryKey('uci_users'))->toBe(['id']);
    });

    it('reports a composite primary key in declared order via primaryKey()', function () {
        Schema::create('uci_composite', function ($table) {
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('member_id');
            $table->primary(['org_id', 'member_id']);
        });

        $inspector = new UniqueColumnInspector(app('db'));

        expect($inspector->primaryKey('uci_composite'))->toBe(['org_id', 'member_id']);
    });

    it('returns null from primaryKey() for a table with only a unique index', function () {
        Schema::create('uci_unique_only', function ($table) {
            $table->string('code')->unique();
        });

        $inspector = new UniqueColumnInspector(app('db'));

        expect($inspector->primaryKey('uci_unique_only'))->toBeNull();
    });

    it('returns null from primaryKey() for a table with no index at all', function () {
        Schema::create('uci_no_index', function ($table) {
            $table->string('name');
        });

        $inspector = new UniqueColumnInspector(app('db'));

        expect($inspector->primaryKey('uci_no_index'))->toBeNull();
    });

    it('memoizes per (connection, table), not per table alone', function () {
        config()->set('database.connections.uci_secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('uci_divergent', function ($table) {
            $table->id();
            $table->string('code')->nullable();
        });

        Schema::connection('uci_secondary')->create('uci_divergent', function ($table) {
            $table->id();
            $table->string('code')->unique();
        });

        $inspector = new UniqueColumnInspector(app('db'));

        $inspector->uniqueConstraints('uci_divergent');
        $secondaryResult = $inspector->uniqueConstraints('uci_divergent', 'uci_secondary');

        expect($secondaryResult)->toContain(['code']);
    });
}
