<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class IdemUser extends Model
    {
        protected $table = 'idem_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class IdemUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'email' => 'safeEmail',
                'role' => fn ($value, $faker, $row) => $value,
            ];
        }

        public function categorical(): array
        {
            return ['role'];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        Schema::create('idem_users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('role');
            $table->string('notes')->nullable();
        });

        // Skewed distribution: 20 'member', 8 'admin', 2 'guest'.
        $roles = array_merge(array_fill(0, 20, 'member'), array_fill(0, 8, 'admin'), array_fill(0, 2, 'guest'));

        foreach ($roles as $i => $role) {
            DB::table('idem_users')->insert([
                'email' => "seed-{$i}@example.com",
                'role' => $role,
                'notes' => "note-{$i}",
            ]);
        }

        config()->set('pii.sanitizers', [
            IdemUser::class => IdemUserSanitizer::class,
        ]);
        config()->set('pii.models', [IdemUser::class]);
    });

    it('runs twice in succession without error', function () {
        $report1 = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        expect($report1->failed())->toBeFalse();
        expect($report1->rowsSanitized())->toBe(30);

        $report2 = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        expect($report2->failed())->toBeFalse();
        expect($report2->rowsSanitized())->toBe(30);
    });

    it('leaves the row count and primary-key set unchanged after each run', function () {
        $idsBefore = DB::table('idem_users')->orderBy('id')->pluck('id')->all();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        $idsAfterRun1 = DB::table('idem_users')->orderBy('id')->pluck('id')->all();
        expect($idsAfterRun1)->toBe($idsBefore);

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        $idsAfterRun2 = DB::table('idem_users')->orderBy('id')->pluck('id')->all();
        expect($idsAfterRun2)->toBe($idsBefore);
    });

    it('leaves undeclared columns byte-identical after each run', function () {
        $notesBefore = DB::table('idem_users')->orderBy('id')->pluck('notes', 'id');

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        $notesAfterRun1 = DB::table('idem_users')->orderBy('id')->pluck('notes', 'id');
        expect($notesAfterRun1->all())->toBe($notesBefore->all());

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        $notesAfterRun2 = DB::table('idem_users')->orderBy('id')->pluck('notes', 'id');
        expect($notesAfterRun2->all())->toBe($notesBefore->all());
    });

    it('keeps unique columns collision-free after the second run', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $total = DB::table('idem_users')->count();
        $distinct = DB::table('idem_users')->distinct()->count('email');

        expect($distinct)->toBe($total);
    });

    it('keeps the categorical distribution within tolerance after the second run', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        // NOTE: run 2 generates fresh random replacements (§6.1.6 of the
        // design doc) — we deliberately do NOT assert value equality with
        // the original seed, only that the categorical proportions are
        // preserved within the Phase 4 tolerance. Do not "fix" this test
        // to assert exact value equality between runs.
        $counts = DB::table('idem_users')->select('role')->get()->countBy('role');

        expect($counts->get('member', 0))->toBeGreaterThanOrEqual(15);
        expect($counts->get('admin', 0))->toBeGreaterThanOrEqual(4);
    });
}
