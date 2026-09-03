<?php

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
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

    it('keeps distinct types distinguishable', function () {
        $tracker = new UniqueValueTracker(app('db'));

        $tracker->claim('uvt_rows', ['a'], [1]);

        expect($tracker->isTaken('uvt_rows', ['a'], ['1']))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], [true]))->toBeFalse();
        expect($tracker->isTaken('uvt_rows', ['a'], [null]))->toBeFalse();
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
}
