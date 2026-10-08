<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\ForeignKeyInspector;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

    class SrEmployee extends Model
    {
        protected $table = 'sr_employees';

        protected $guarded = [];

        public $timestamps = false;
    }

    class SrNode extends Model
    {
        protected $table = 'sr_nodes';

        protected $guarded = [];

        public $timestamps = false;
    }

    class SrStaffIdOnlySanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['staff_id' => Keyed::pattern('sr-staff', 'EMP-#####')];
        }
    }

    class SrOptInSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'staff_id' => Keyed::pattern('sr-staff', 'EMP-#####'),
                'manager_staff_id' => Keyed::pattern('sr-staff', 'EMP-#####'),
            ];
        }

        public function mirrors(): array
        {
            return [
                'staff_id' => ['sr_employees.manager_staff_id'],
                'manager_staff_id' => ['sr_employees.staff_id'],
            ];
        }
    }

    class SrTreeParentSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['parent_id' => fn () => 1];
        }
    }

    class SrTreeIdSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['id' => fn () => 1];
        }
    }

    /** @return array{string, string} the exception class and message the run fails with, or empty strings */
    function srThrownBy(string $model, string $sanitizer): array
    {
        config()->set('pii.sanitizers', [$model => $sanitizer]);
        config()->set('pii.models', [$model]);

        try {
            app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }

        return ['', ''];
    }

    beforeEach(function () {
        config()->set('pii.keyed.key', str_repeat("\x03", 32));

        Schema::create('sr_employees', function ($table) {
            $table->id();
            $table->string('staff_id')->unique();
            $table->string('manager_staff_id')->nullable();
            $table->foreign('manager_staff_id')->references('staff_id')->on('sr_employees');
        });

        Schema::create('sr_nodes', function ($table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('label')->nullable();
            $table->foreign('parent_id')->references('id')->on('sr_nodes');
        });

        DB::table('sr_employees')->insert([
            ['id' => 1, 'staff_id' => 'S001', 'manager_staff_id' => null],
            ['id' => 2, 'staff_id' => 'S002', 'manager_staff_id' => 'S001'],
            ['id' => 3, 'staff_id' => 'S003', 'manager_staff_id' => 'S001'],
        ]);
        DB::table('sr_nodes')->insert([
            ['id' => 1, 'parent_id' => null, 'label' => 'root'],
            ['id' => 2, 'parent_id' => 1, 'label' => 'child'],
        ]);
    });

    it('reports a column referenced only by a self-referencing key as inbound-referenced', function () {
        $inbound = (new ForeignKeyInspector)->inboundReferencedColumns(DB::connection(), 'sr_employees');

        expect($inbound)->toBe(['staff_id' => 'sr_employees']);
    });

    it('rejects a self-referenced natural-key column at boot without the opt-in', function () {
        $before = DB::table('sr_employees')->orderBy('id')->get()->all();

        [$class, $message] = srThrownBy(SrEmployee::class, SrStaffIdOnlySanitizer::class);

        expect($class)->toBe(UnsafeColumnException::class);
        expect($message)->toContain('SrEmployee::$staff_id is referenced by a foreign key on table "sr_employees" and cannot be sanitized.');
        expect(DB::table('sr_employees')->orderBy('id')->get()->all())->toEqual($before);
    });

    it('accepts the self-referenced column when mirrors() declares the same-table referencing column, with foreign keys enforced', function () {
        DB::statement('PRAGMA foreign_keys = ON');

        config()->set('pii.sanitizers', [SrEmployee::class => SrOptInSanitizer::class]);
        config()->set('pii.models', [SrEmployee::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->foreignKeysSuspended)->toBeTrue();

        $rows = DB::table('sr_employees')->orderBy('id')->get()->keyBy('id');

        expect($rows[1]->staff_id)->not->toBe('S001')->toMatch('/^EMP-\d{5}$/');
        expect($rows[2]->manager_staff_id)->toBe($rows[1]->staff_id);
        expect($rows[3]->manager_staff_id)->toBe($rows[1]->staff_id);
        expect($rows[1]->manager_staff_id)->toBeNull();
        expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
        expect((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
    });

    it('still rejects the foreign-key column of a plain self-referencing tree table', function () {
        [$class, $message] = srThrownBy(SrNode::class, SrTreeParentSanitizer::class);

        expect($class)->toBe(UnsafeColumnException::class);
        expect($message)->toContain('SrNode::$parent_id is a foreign key on table "sr_nodes" (references "sr_nodes") and cannot be sanitized.');
    });

    it('still rejects the self-referenced primary key of a tree table with the primary-key message', function () {
        [$class, $message] = srThrownBy(SrNode::class, SrTreeIdSanitizer::class);

        expect($class)->toBe(UnsafeColumnException::class);
        expect($message)->toBe(UnsafeColumnException::primaryKey(SrNode::class, 'id', SrTreeIdSanitizer::class, 'sr_nodes')->getMessage());
    });
}
