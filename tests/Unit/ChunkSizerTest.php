<?php

namespace {
    use Illuminate\Database\Eloquent\Model;

    class ChunkSizerUser extends Model
    {
        protected $table = 'chunk_sizer_users';

        protected $guarded = [];

        public $timestamps = false;
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkSizer;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;

    beforeEach(function () {
        Schema::create('chunk_sizer_users', function ($table) {
            $table->id();
            $table->string('email')->nullable();
        });
    });

    function seedChunkSizerUsers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('chunk_sizer_users')->insert(['email' => "user-{$i}@example.com"]);
        }
    }

    it('returns the row count as a single chunk when the table is at or below the minimum', function () {
        seedChunkSizerUsers(300);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(300);
        expect($sizer->wasAutomatic())->toBeTrue();
    });

    it('never returns zero for an empty table', function () {
        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(1);
    });

    it('divides by the target chunk count and clamps to the minimum', function () {
        seedChunkSizerUsers(2000);

        config()->set('pii.chunk.target_chunks', 20);
        config()->set('pii.chunk.min', 10);
        config()->set('pii.chunk.max', 5000);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(100);
        expect($sizer->wasAutomatic())->toBeTrue();
    });

    it('clamps to the configured maximum', function () {
        seedChunkSizerUsers(2000);

        config()->set('pii.chunk.target_chunks', 20);
        config()->set('pii.chunk.min', 10);
        config()->set('pii.chunk.max', 25);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(25);
    });

    it('prefers the manual RunOptions override over config and the automatic algorithm', function () {
        seedChunkSizerUsers(2000);

        config()->set('pii.chunk.size', 700);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions(chunkSize: 42)))->toBe(42);
        expect($sizer->wasAutomatic())->toBeFalse();
    });

    it('prefers the config chunk size over the automatic algorithm', function () {
        seedChunkSizerUsers(2000);

        config()->set('pii.chunk.size', 700);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(700);
        expect($sizer->wasAutomatic())->toBeFalse();
    });

    it('ignores a non-positive override and falls back down the chain', function () {
        seedChunkSizerUsers(300);

        config()->set('pii.chunk.size', 0);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions(chunkSize: 0)))->toBe(300);
        expect($sizer->wasAutomatic())->toBeTrue();
    });

    it('resolves chunk sub-key defaults when the published config omits them', function () {
        seedChunkSizerUsers(300);

        config()->set('pii.chunk', ['size' => null]);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(300);
        expect($sizer->wasAutomatic())->toBeTrue();
    });

    it('issues exactly one COUNT query per table per run', function () {
        seedChunkSizerUsers(50);

        $sizer = new ChunkSizer;

        DB::enableQueryLog();
        DB::flushQueryLog();

        $sizer->sizeFor(new ChunkSizerUser, new RunOptions);
        $sizer->sizeFor(new ChunkSizerUser, new RunOptions);

        $countQueries = array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_contains(strtolower($entry['query']), 'count(')
        );

        DB::disableQueryLog();

        expect($countQueries)->toHaveCount(1);
    });
}
