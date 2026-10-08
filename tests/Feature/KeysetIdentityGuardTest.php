<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    /**
     * A composite-key table, paged by key set. The tests replace the raw
     * identity of every row read, standing in for a driver that hands back a
     * value which cannot be bound again (a PostgreSQL bytea stream).
     */
    class KigRow extends Model
    {
        protected $table = 'kig_rows';

        protected $primaryKey = null;

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;

        /** @var array<string, mixed> */
        public static array $replaceRaw = [];

        protected static function booted(): void
        {
            static::retrieved(function (KigRow $row): void {
                $replace = array_map(fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value, self::$replaceRaw);

                $row->setRawAttributes(array_merge($row->getAttributes(), $replace), true);
            });
        }
    }

    class KigRowSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['secret' => fn ($value) => 'clean-'.$value];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        Schema::create('kig_rows', function ($table) {
            $table->integer('a');
            $table->integer('b');
            $table->string('secret');
            $table->primary(['a', 'b']);
        });

        DB::table('kig_rows')->insert([
            ['a' => 1, 'b' => 1, 'secret' => 's1'],
            ['a' => 1, 'b' => 2, 'secret' => 's2'],
            ['a' => 1, 'b' => 3, 'secret' => 's3'],
            ['a' => 1, 'b' => 4, 'secret' => 's4'],
        ]);

        config()->set('pii.sanitizers', [KigRow::class => KigRowSanitizer::class]);
        config()->set('pii.models', [KigRow::class]);
    });

    afterEach(function () {
        KigRow::$replaceRaw = [];
    });

    it('stops with a named error when the last identity value read is a stream, not a bindable value (BUG-21)', function () {
        KigRow::$replaceRaw = ['b' => fn () => fopen('php://memory', 'r')];

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2)))
            ->toThrow(UnpageableTableException::class, '[laravel-pii-sanitizer] Stopped paging kig_rows (KigRow): identity column $b was read back as a value of type resource (stream), which cannot be bound back to select the next page.');
    });

    it('stops with a named error instead of looping when a page ends on the same identity as the page before (BUG-21)', function () {
        KigRow::$replaceRaw = ['a' => 1, 'b' => 1];

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2)))
            ->toThrow(UnpageableTableException::class, '[laravel-pii-sanitizer] Stopped paging kig_rows (KigRow): identity column $a ended two pages on the same value, so paging would never finish.');
    });

    it('pages a composite key to the end when every identity value binds back', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 2));

        expect($report->failed())->toBeFalse();
        expect(DB::table('kig_rows')->orderBy('b')->pluck('secret')->all())->toBe(['clean-s1', 'clean-s2', 'clean-s3', 'clean-s4']);
    });
}
