<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class CccProfile extends Model
    {
        protected $table = 'ccc_profiles';

        protected $guarded = [];

        public $timestamps = false;

        protected $casts = ['settings' => 'array', 'secret' => 'encrypted'];
    }

    class CccProfileSanitizer extends Sanitizer
    {
        public static string $column = 'settings';

        public function fields(): array
        {
            return [static::$column => fn () => null];
        }

        public function categorical(): array
        {
            return [static::$column];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    // BUG-21: a categorical column is sampled from raw stored values, so a
    // cast on it would encode an already-encoded value a second time (a JSON
    // string inside a JSON string, or a ciphertext encrypted again). Such a
    // column is refused at boot, before any row is read or written.

    beforeEach(function () {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Schema::create('ccc_profiles', function ($table) {
            $table->id();
            $table->text('settings')->nullable();
            $table->text('secret')->nullable();
        });

        foreach ([['a' => 1], ['a' => 1], ['b' => 2]] as $i => $settings) {
            CccProfile::query()->create(['settings' => $settings, 'secret' => "secret-{$i}"]);
        }

        config()->set('pii.sanitizers', [CccProfile::class => CccProfileSanitizer::class]);
        config()->set('pii.models', [CccProfile::class]);
    });

    it('refuses a categorical column with an array or encrypted cast and leaves every row readable', function (string $column) {
        CccProfileSanitizer::$column = $column;

        $before = DB::table('ccc_profiles')->orderBy('id')->get()->all();

        DB::flushQueryLog();
        DB::enableQueryLog();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow(InvalidCategoricalColumnException::class, InvalidCategoricalColumnException::castBearing(CccProfile::class, $column, CccProfileSanitizer::class)->getMessage());

        $reads = array_filter(DB::getQueryLog(), fn (array $entry): bool => preg_match('/\bfrom\s+"ccc_profiles"/i', $entry['query']) === 1);

        expect($reads)->toBe([]);
        expect(DB::table('ccc_profiles')->orderBy('id')->get()->all())->toEqual($before);

        $profiles = CccProfile::query()->orderBy('id')->get();

        expect($profiles[0]->settings)->toBe(['a' => 1]);
        expect($profiles[2]->secret)->toBe('secret-2');
    })->with(['array cast' => ['settings'], 'encrypted cast' => ['secret']]);
}
