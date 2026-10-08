<?php

namespace {
    enum UvtStatus: string
    {
        case Open = 'open';
        case Closed = 'closed';
    }

    enum UvtNumber: int
    {
        case Seven = 7;
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;
    use Shahirul22\LaravelPiiSanitizer\UniqueValueTracker;

    beforeEach(function () {
        Schema::create('uvt_rows', function ($table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('a')->nullable();
            $table->string('b')->nullable();
        });

        DB::table('uvt_rows')->insert([
            ['email' => 'existing@example.com', 'a' => null, 'b' => null],
        ]);
    });

    it('reports pre-existing seeded values as taken and absent values as not taken', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->seed('uvt_rows', ['email']);

        expect($tracker->isTaken('uvt_rows', ['email'], ['existing@example.com']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], ['absent@example.com']))->toBeFalse();
    });

    it('reports a newly-claimed value as taken without seeding', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['email'], ['fresh@example.com']);

        expect($tracker->isTaken('uvt_rows', ['email'], ['fresh@example.com']))->toBeTrue();
    });

    it('is idempotent — a second seed() call issues no additional query', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->seed('uvt_rows', ['email']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $tracker->seed('uvt_rows', ['email']);

        expect(DB::getQueryLog())->toBe([]);
    });

    it('canonicalizes tuple keys so members never collide across the separator', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a', 'b'], ['a|b', 'c']);

        expect($tracker->isTaken('uvt_rows', ['a', 'b'], ['a', 'b|c']))->toBeFalse();
    });

    it('keeps null and non-numeric strings apart from numbers', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a'], [1]);

        expect($tracker->isTaken('uvt_rows', ['a'], [null]))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ['1a']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ['one']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ['"1"']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], [' 1']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ['1e0']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ["1\n"]))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ['.']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ['']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], [11]))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], ['0.1']))->toBeFalse();
    });

    it('compares numbers by value whatever their PHP type, as the database does (BUG-23)', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a'], [5]);
        $tracker->claim('uvt_rows', ['b'], ['0.50']);
        $tracker->claim('uvt_rows', ['email'], [true]);

        expect($tracker->isTaken('uvt_rows', ['a'], ['5']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], ['05']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], ['+5']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], [5.0]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], ['5.000']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], [-5]))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['b'], [0.5]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['b'], ['.5']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['b'], ['0.5']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['b'], ['0.05']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['b'], ['5']))->toBeFalse();
        // A boolean is written as 1 or 0.
        expect($tracker->isTaken('uvt_rows', ['email'], [1]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], ['1']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], [false]))->toBeFalse();
    });

    it('treats zero, negative zero and a zero with a fraction as one value', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a'], ['-0.00']);

        expect($tracker->isTaken('uvt_rows', ['a'], [0]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], [false]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], ['0']))->toBeTrue();
    });

    it('keeps integers beyond PHP_INT_MAX exact', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a'], ['123456789012345678901234567890']);

        expect($tracker->isTaken('uvt_rows', ['a'], ['0123456789012345678901234567890']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], ['123456789012345678901234567891']))->toBeFalse();
    });

    it('sees a seeded integer as taken when the candidate is the same number as a string (BUG-23)', function () {
        Schema::create('uvt_badges', function ($table) {
            $table->id();
            $table->integer('badge')->unique();
            $table->decimal('ratio', 8, 2)->unique();
        });

        DB::table('uvt_badges')->insert(['badge' => 5, 'ratio' => '1.50']);

        $tracker = new UniqueValueTracker(app('db'));

        $tracker->seed('uvt_badges', ['badge']);
        $tracker->seed('uvt_badges', ['ratio']);

        expect($tracker->isTaken('uvt_badges', ['badge'], ['5']))->toBeTrue();
        expect($tracker->isTaken('uvt_badges', ['ratio'], [1.5]))->toBeTrue();
        expect($tracker->isTaken('uvt_badges', ['ratio'], ['1.5']))->toBeTrue();
    });

    it('compares numbers by value on a numeric column and as stored text on a text column (BUG-23)', function () {
        Schema::create('uvt_codes', function ($table) {
            $table->id();
            $table->string('code')->unique();
            $table->integer('badge')->unique();
            $table->decimal('ratio', 8, 2)->unique();
            $table->boolean('flag');
            $table->string('label');
            $table->unique(['flag', 'label']);
        });

        DB::table('uvt_codes')->insert(['code' => '007', 'badge' => 7, 'ratio' => '0.50', 'flag' => true, 'label' => '12']);

        $tracker = new UniqueValueTracker(app('db'), app(ColumnConstraintInspector::class));

        foreach ([['code'], ['badge'], ['ratio'], ['flag', 'label']] as $columns) {
            $tracker->seed('uvt_codes', $columns);
        }

        // Text: '007' and '7' are two values; a number is compared as the text it is stored as.
        expect($tracker->isTaken('uvt_codes', ['code'], ['007']))->toBeTrue();
        expect($tracker->isTaken('uvt_codes', ['code'], ['7']))->toBeFalse();
        expect($tracker->isTaken('uvt_codes', ['code'], [7]))->toBeFalse();

        $tracker->claim('uvt_codes', ['code'], [12345]);
        expect($tracker->isTaken('uvt_codes', ['code'], ['12345']))->toBeTrue();
        expect($tracker->isTaken('uvt_codes', ['code'], ['012345']))->toBeFalse();

        // Integer and decimal: one value whatever the type or the zeros.
        expect($tracker->isTaken('uvt_codes', ['badge'], ['007']))->toBeTrue();
        expect($tracker->isTaken('uvt_codes', ['badge'], [7.0]))->toBeTrue();
        expect($tracker->isTaken('uvt_codes', ['ratio'], [0.5]))->toBeTrue();
        expect($tracker->isTaken('uvt_codes', ['ratio'], ['.5']))->toBeTrue();

        // A composite tuple compares each member by its own column.
        expect($tracker->isTaken('uvt_codes', ['flag', 'label'], [1, '12']))->toBeTrue();
        expect($tracker->isTaken('uvt_codes', ['flag', 'label'], [true, 12]))->toBeTrue();
        expect($tracker->isTaken('uvt_codes', ['flag', 'label'], [true, '012']))->toBeFalse();
    });

    it('is built by the container with the column inspector', function () {
        expect((new ReflectionProperty(UniqueValueTracker::class, 'columns'))->getValue(app(UniqueValueTracker::class)))
            ->toBeInstanceOf(ColumnConstraintInspector::class);
    });

    it('keys a backed enum by its value', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a'], ['Open']);
        $tracker->claim('uvt_rows', ['b'], [7]);

        expect($tracker->isTaken('uvt_rows', ['a'], [UvtStatus::Open]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['b'], [UvtNumber::Seven]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a'], [UvtStatus::Closed]))->toBeFalse();
    });

    it('namespaces claims by column tuple and table so they never leak across', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a'], ['shared-value']);

        expect($tracker->isTaken('uvt_rows', ['b'], ['shared-value']))->toBeFalse();
        expect($tracker->isTaken('other_table', ['a'], ['shared-value']))->toBeFalse();
    });

    it('is monotonic — a seeded value stays taken after a replacement is claimed for the same row', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->seed('uvt_rows', ['email']);
        $tracker->claim('uvt_rows', ['email'], ['replacement@example.com']);

        expect($tracker->isTaken('uvt_rows', ['email'], ['existing@example.com']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], ['replacement@example.com']))->toBeTrue();
    });

    it('seeds from the given connection, not the default connection', function () {
        config()->set('database.connections.uvt_secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::connection('uvt_secondary')->create('uvt_rows', function ($table) {
            $table->id();
            $table->string('email')->nullable();
        });

        DB::connection('uvt_secondary')->table('uvt_rows')->insert([
            ['email' => 'only-on-secondary@example.com'],
        ]);

        $tracker = new UniqueValueTracker(app('db'));

        $tracker->seed('uvt_rows', ['email'], 'uvt_secondary');

        // Checked against the DEFAULT connection's namespace (no $connection
        // arg to isTaken) so a pre-fix implementation — where seed()'s query
        // already correctly targeted uvt_secondary (that part predates this
        // fix) but namespaceKey() didn't include $connection — would still
        // wrongly report this as taken under the default namespace too.
        // Only correct per-connection namespacing keeps them apart.
        expect($tracker->isTaken('uvt_rows', ['email'], ['only-on-secondary@example.com']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['email'], ['only-on-secondary@example.com'], 'uvt_secondary'))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], ['existing@example.com'], 'uvt_secondary'))->toBeFalse();
    });

    it('keeps the taken-set namespaced by connection, so the same table/column pair on two connections never collides', function () {
        config()->set('database.connections.uvt_secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['email'], ['shared@example.com']);

        // Claimed on the default connection only — must not be seen as
        // taken under the same table/column pair on a different connection.
        expect($tracker->isTaken('uvt_rows', ['email'], ['shared@example.com'], 'uvt_secondary'))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['email'], ['shared@example.com']))->toBeTrue();
    });

    it('reset() clears everything including the seeded flag, so a later seed() re-queries', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->seed('uvt_rows', ['email']);
        $tracker->reset();

        expect($tracker->isTaken('uvt_rows', ['email'], ['existing@example.com']))->toBeFalse();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $tracker->seed('uvt_rows', ['email']);

        expect(DB::getQueryLog())->not->toBe([]);
    });

    it('compares string members case-insensitively, as a case-insensitive collation does (BUG-38)', function () {
        DB::table('uvt_rows')->insert(['email' => 'KAREN', 'a' => 'Tenant', 'b' => 'Slug']);

        $tracker = new UniqueValueTracker(app('db'));

        $tracker->seed('uvt_rows', ['email']);
        $tracker->seed('uvt_rows', ['a', 'b']);
        $tracker->claim('uvt_rows', ['email'], ['Bob']);

        expect($tracker->isTaken('uvt_rows', ['email'], ['karen']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], ['BOB']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], ['ÉLODIE']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a', 'b'], ['TENANT', 'slug']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a', 'b'], ['tenant', 'other']))->toBeFalse();
    });

    it('keeps distinct invalid-UTF-8 members distinct instead of folding them into one key', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['email'], ["\xff"]);
        $tracker->claim('uvt_rows', ['a', 'b'], ["\xfe\xff", 'x']);

        expect($tracker->isTaken('uvt_rows', ['email'], ["\xff"]))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['email'], ["\xfe"]))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['email'], ["\xc3\x28"]))->toBeFalse();
        // No invalid member may collide with the key of a valid string either.
        expect($tracker->isTaken('uvt_rows', ['email'], ['']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['email'], ['ff']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a', 'b'], ["\xfe\xff", 'x']))->toBeTrue();
        expect($tracker->isTaken('uvt_rows', ['a', 'b'], ["\xfe\xfe", 'x']))->toBeFalse();
    });
}
