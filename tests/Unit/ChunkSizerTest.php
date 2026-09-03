<?php

namespace {
    use Illuminate\Database\Eloquent\Model;

    class ChunkSizerUser extends Model
    {
        protected $table = 'chunk_sizer_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class ChunkSizerSecondaryUser extends Model
    {
        protected $connection = 'chunk_sizer_secondary';

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

    it('falls back to DEFAULT_TARGET_CHUNKS/DEFAULT_MAX when target_chunks/max are non-numeric, above the min threshold', function () {
        // Row count (3000) is deliberately well above ChunkSizer::DEFAULT_MIN
        // (500) so the early-return "count <= min" branch never fires and
        // sizeFor() actually reaches the target_chunks/max intConfig() reads
        // below it — the two existing "partial config" tests both use row
        // counts at or under the min and so never exercise this fallback.
        seedChunkSizerUsers(3000);

        config()->set('pii.chunk', ['size' => null, 'min' => 100]);

        $sizer = new ChunkSizer;

        $expected = max(100, min(ChunkSizer::DEFAULT_MAX, (int) ceil(3000 / ChunkSizer::DEFAULT_TARGET_CHUNKS)));

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe($expected);
        expect($sizer->wasAutomatic())->toBeTrue();
    });

    it('counts each connection\'s same-named table separately, not sharing a cached row count', function () {
        config()->set('database.connections.chunk_sizer_secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::connection('chunk_sizer_secondary')->create('chunk_sizer_users', function ($table) {
            $table->id();
            $table->string('email')->nullable();
        });

        seedChunkSizerUsers(50);

        for ($i = 0; $i < 400; $i++) {
            DB::connection('chunk_sizer_secondary')->table('chunk_sizer_users')->insert(['email' => "secondary-{$i}@example.com"]);
        }

        $sizer = new ChunkSizer;

        // Count the default connection's table first, so a pre-fix
        // (table-name-only cache key) implementation would serve this
        // cached 50 back for the secondary connection's identically-named
        // table below instead of re-counting.
        expect($sizer->countFor(new ChunkSizerUser))->toBe(50);
        expect($sizer->countFor(new ChunkSizerSecondaryUser))->toBe(400);
    });

    it('re-counts after reset() instead of returning a stale cached count', function () {
        seedChunkSizerUsers(50);

        $sizer = new ChunkSizer;

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(50);

        seedChunkSizerUsers(50); // now 100 rows total

        // Without reset(), sizeFor() would still return the memoized 50 —
        // this is the staleness a singleton-bound ChunkSizer must not carry
        // across two runs in the same process.
        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(50);

        $sizer->reset();

        expect($sizer->sizeFor(new ChunkSizerUser, new RunOptions))->toBe(100);
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
