<?php

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ForeignKeyInspector;

    function fkiCreateSchema(): void
    {
        Schema::create('fki_companies', function ($table) {
            $table->id();
        });

        Schema::create('fki_users', function ($table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('fki_companies');
        });

        Schema::create('fki_employees', function ($table) {
            $table->id();
            $table->string('staff_id')->unique();
            $table->string('manager_staff_id')->nullable();
            $table->foreign('manager_staff_id')->references('staff_id')->on('fki_employees');
        });

        Schema::create('fki_parents', function ($table) {
            $table->id();
            $table->string('x');
            $table->string('y');
            $table->unique(['x', 'y']);
        });

        Schema::create('fki_children', function ($table) {
            $table->id();
            $table->string('a');
            $table->string('b');
            $table->foreign(['a', 'b'])->references(['x', 'y'])->on('fki_parents');
        });
    }

    beforeEach(function () {
        fkiCreateSchema();
    });

    afterEach(function () {
        DB::connection()->setTablePrefix('');
    });

    it('returns the inbound edge for a referenced column', function () {
        $edges = (new ForeignKeyInspector)->edgesTouching(DB::connection(), 'fki_companies', 'id');

        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_table'])->toBe('fki_users');
        expect($edges[0]['child_columns'])->toBe(['company_id']);
        expect($edges[0]['parent_table'])->toBe('fki_companies');
        expect($edges[0]['parent_columns'])->toBe(['id']);
        expect($edges[0]['on_update'])->toBeString();
    });

    it('returns the same edge from the outbound side', function () {
        $inspector = new ForeignKeyInspector;

        $inbound = $inspector->edgesTouching(DB::connection(), 'fki_companies', 'id');
        $outbound = $inspector->edgesTouching(DB::connection(), 'fki_users', 'company_id');

        expect($outbound)->toBe($inbound);
    });

    it('includes a same-table edge', function () {
        $edges = (new ForeignKeyInspector)->edgesTouching(DB::connection(), 'fki_employees', 'staff_id');

        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_table'])->toBe('fki_employees');
        expect($edges[0]['parent_table'])->toBe('fki_employees');
        expect($edges[0]['child_columns'])->toBe(['manager_staff_id']);
        expect($edges[0]['parent_columns'])->toBe(['staff_id']);
    });

    it('returns a composite edge whole', function () {
        $edges = (new ForeignKeyInspector)->edgesTouching(DB::connection(), 'fki_children', 'a');

        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_columns'])->toBe(['a', 'b']);
        expect($edges[0]['parent_columns'])->toBe(['x', 'y']);
    });

    it('returns no edge for a column with no foreign key on either side', function () {
        expect((new ForeignKeyInspector)->edgesTouching(DB::connection(), 'fki_users', 'id'))->toBe([]);
    });

    it('returns unprefixed table names under a configured table prefix', function () {
        Schema::dropAllTables();
        DB::connection()->setTablePrefix('wp_');
        fkiCreateSchema();

        $edges = (new ForeignKeyInspector)->edgesTouching(DB::connection(), 'fki_companies', 'id');

        expect($edges)->toHaveCount(1);
        expect($edges[0]['child_table'])->toBe('fki_users');
        expect($edges[0]['parent_table'])->toBe('fki_companies');
    });

    it('memoises the all-edges sweep per connection', function () {
        $inspector = new ForeignKeyInspector;

        $inspector->edgesTouching(DB::connection(), 'fki_companies', 'id');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $inspector->edgesTouching(DB::connection(), 'fki_children', 'a');

        expect(DB::getQueryLog())->toBe([]);
    });

    it('reports a column referenced only by a self-referencing key as inbound-referenced', function () {
        $inbound = (new ForeignKeyInspector)->inboundReferencedColumns(DB::connection(), 'fki_employees');

        expect($inbound)->toHaveKey('staff_id');
        expect($inbound['staff_id'])->toBe('fki_employees');
    });
}
