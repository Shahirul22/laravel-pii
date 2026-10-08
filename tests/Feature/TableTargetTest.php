<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class PhUserModel extends Model
    {
        protected $table = 'ph_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PhUserModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['name' => 'name'];
        }
    }

    class PhRoleUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['note' => fn () => 'redacted'];
        }
    }

    class PhRoleUserBadSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['user_id' => fn () => 999];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        Schema::create('ph_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('ph_roles', function ($table) {
            $table->id();
            $table->string('label');
        });

        Schema::create('ph_role_user', function ($table) {
            $table->foreignId('user_id')->constrained('ph_users');
            $table->foreignId('role_id')->constrained('ph_roles');
            $table->string('note');
            $table->unique(['user_id', 'role_id']);
        });

        DB::table('ph_users')->insert(['id' => 1, 'name' => 'Alice']);
        DB::table('ph_users')->insert(['id' => 2, 'name' => 'Bob']);
        DB::table('ph_roles')->insert(['id' => 1, 'label' => 'admin']);
        DB::table('ph_roles')->insert(['id' => 2, 'label' => 'editor']);

        DB::table('ph_role_user')->insert(['user_id' => 1, 'role_id' => 1, 'note' => 'secret one']);
        DB::table('ph_role_user')->insert(['user_id' => 2, 'role_id' => 2, 'note' => 'secret two']);

        config()->set('pii.sanitizers', [PhUserModel::class => PhUserModelSanitizer::class]);
        config()->set('pii.models', [PhUserModel::class]);
        config()->set('pii.tables', ['ph_role_user' => PhRoleUserSanitizer::class]);
    });

    it('sanitizes a model-less pivot table via a table-level target (R7.4)', function () {
        $before = DB::table('ph_role_user')->orderBy('user_id')->orderBy('role_id')->get(['user_id', 'role_id']);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5));

        expect($report->failed())->toBeFalse();
        expect($report->models)->toHaveCount(2);

        expect($report->models[0]->modelClass)->toBe(PhUserModel::class);
        expect($report->models[1]->modelClass)->toBe('table:ph_role_user');

        $after = DB::table('ph_role_user')->orderBy('user_id')->orderBy('role_id')->get();

        expect($after->pluck('user_id', 'role_id')->all())->toBe($before->pluck('user_id', 'role_id')->all());

        foreach ($after as $row) {
            expect($row->note)->toBe('redacted');
        }

        $firstKey = $report->models[1]->chunks[0]->firstKey;
        expect($firstKey)->toBeArray();
        expect(array_keys($firstKey))->toEqualCanonicalizing(['user_id', 'role_id']);
    });

    it('resolves and rejects table targets through resolveTable()', function () {
        $resolved = app(SanitizerResolverContract::class)->resolveTable('ph_role_user');
        expect($resolved)->toBeInstanceOf(PhRoleUserSanitizer::class);

        expect(app(SanitizerResolverContract::class)->resolveTable('unmapped'))->toBeNull();
    });

    it('rejects a pivot sanitizer declaring an outbound FK column via the reused SchemaGuard', function () {
        config()->set('pii.tables', ['ph_role_user' => PhRoleUserBadSanitizer::class]);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5)))
            ->toThrow(UnsafeColumnException::class);
    });

    it('excludes table targets entirely when the --model filter is used', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(models: [PhUserModel::class], chunkSize: 5));

        expect($report->models)->toHaveCount(1);
        expect($report->models[0]->modelClass)->toBe(PhUserModel::class);

        $row = DB::table('ph_role_user')->where('user_id', 1)->where('role_id', 1)->first();
        expect($row->note)->toBe('secret one');
    });

    it('rejects a pii.tables config that is a list instead of a map', function () {
        config()->set('pii.tables', ['ph_role_user']);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5)))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects a pii.tables entry with an empty sanitizer class string', function () {
        config()->set('pii.tables', ['ph_role_user' => '']);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5)))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('writes nothing on --dry-run for a pivot target and still reports column counts', function () {
        $before = DB::table('ph_role_user')->orderBy('user_id')->get();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 5, dryRun: true));

        $after = DB::table('ph_role_user')->orderBy('user_id')->get();
        expect($after)->toEqual($before);

        $tableReport = $report->models[1];
        expect($tableReport->columnCounts['note'])->toBe(2);
    });
}
