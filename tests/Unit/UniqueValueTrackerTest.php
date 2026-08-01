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
