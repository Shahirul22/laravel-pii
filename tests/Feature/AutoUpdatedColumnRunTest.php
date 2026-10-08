<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class AucrRow extends Model
    {
        protected $table = 'aucr_rows';

        protected $guarded = [];

        public $timestamps = false;
    }

    class AucrRowSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => fn () => 'clean@x.test'];
        }
    }
}

namespace {
    use Illuminate\Database\Connection;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    it('keeps the columns the database would rewrite on UPDATE out of the run by assigning them to themselves (BUG-25)', function () {
        Schema::create('aucr_rows', function ($table) {
            $table->id();
            $table->string('email');
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('aucr_rows')->insert([
            ['email' => 'a@x.test', 'updated_at' => '2020-01-02 03:04:05'],
            ['email' => 'b@x.test', 'updated_at' => '2021-01-02 03:04:05'],
        ]);

        // SQLite has no ON UPDATE CURRENT_TIMESTAMP; report the column the
        // way the MySQL schema would.
        app()->instance(ColumnConstraintInspector::class, new class(app('db')) extends ColumnConstraintInspector
        {
            public function autoUpdatedColumns(Connection $connection, string $table): array
            {
                return $table === 'aucr_rows' ? ['updated_at'] : [];
            }
        });

        config()->set('pii.sanitizers', [AucrRow::class => AucrRowSanitizer::class]);
        config()->set('pii.models', [AucrRow::class]);

        $updates = [];
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with($query->sql, 'UPDATE')) {
                $updates[] = $query->sql;
            }
        });

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($updates)->toBe([
            'UPDATE "aucr_rows" SET "email" = CASE "id" WHEN ? THEN ? WHEN ? THEN ? END, "updated_at" = "updated_at" WHERE "id" IN (?, ?)',
        ]);
        expect(DB::table('aucr_rows')->orderBy('id')->get()->map(fn ($row) => [$row->email, $row->updated_at])->all())
            ->toBe([['clean@x.test', '2020-01-02 03:04:05'], ['clean@x.test', '2021-01-02 03:04:05']]);
    });
}
