<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Json;

    class JpInvalidDoc extends Model
    {
        protected $table = 'jp_invalid_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'array', 'vault' => 'encrypted:array', 'blob' => 'json'];
        }
    }

    class JpInvalidSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'settings' => Json::paths(['profile->email' => 'x@example.test']),
                'vault' => Json::paths(['ic' => '000000-00-0000']),
                'blob' => Json::paths(['profile->email' => 'x@example.test']),
            ];
        }
    }

    class JpInvalidRequiredDoc extends Model
    {
        protected $table = 'jp_invalid_required';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'array'];
        }
    }

    class JpInvalidRequiredSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['settings' => Json::paths(['profile->email' => 'x@example.test'])];
        }
    }

    function jpInvalidSnapshot(): array
    {
        return DB::table('jp_invalid_docs')->orderBy('id')->get()->all();
    }

    beforeEach(function () {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Schema::create('jp_invalid_docs', function ($table) {
            $table->id();
            $table->text('settings')->nullable();
            $table->text('vault')->nullable();
            $table->text('blob')->nullable();
        });

        DB::table('jp_invalid_docs')->insert([
            'id' => 1,
            'settings' => '{"profile":{"email":"p@q.r"}}',
            'vault' => Crypt::encryptString('{"ic":"900101-14-5678"}'),
            'blob' => '{"profile":{"email":"p@q.r"}}',
        ]);

        config()->set('pii.sanitizers', [JpInvalidDoc::class => JpInvalidSanitizer::class]);
        config()->set('pii.models', [JpInvalidDoc::class]);
    });

    it('fails the chunk loudly on undecodable text under an array-ish cast and leaves every row unchanged', function (string $column, callable $stored) {
        DB::table('jp_invalid_docs')->insert(['id' => 2, 'settings' => null, 'vault' => null, 'blob' => null]);
        DB::table('jp_invalid_docs')->where('id', 2)->update([$column => $stored()]);

        $before = jpInvalidSnapshot();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(InvalidReplacementValueException::class);
        expect($chunk->failureMessage)->toBe('[laravel-pii-sanitizer] $'.$column.': The column value is not a valid JSON document, so its declared paths cannot be rewritten.');
        expect(jpInvalidSnapshot())->toEqual($before);
    })->with([
        'empty string under array' => ['settings', fn () => ''],
        'malformed text under array' => ['settings', fn () => '{"profile":'],
        'plain text under array' => ['settings', fn () => 'secret-value'],
        'empty string under json' => ['blob', fn () => ''],
        'malformed text under json' => ['blob', fn () => '{"profile":'],
        'empty string under encrypted:array' => ['vault', fn () => Crypt::encryptString('')],
        'malformed text under encrypted:array' => ['vault', fn () => Crypt::encryptString('{"ic":')],
    ]);

    it('never prints the undecodable value in the failure message', function () {
        DB::table('jp_invalid_docs')->where('id', 1)->update(['settings' => 'top-secret-not-json']);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->models[0]->chunks[0]->failureMessage)->not->toContain('top-secret-not-json');
    });

    it('still accepts SQL NULL and the JSON literal null without error', function () {
        DB::table('jp_invalid_docs')->insert(['id' => 2, 'settings' => null, 'vault' => null, 'blob' => null]);
        DB::table('jp_invalid_docs')->insert([
            'id' => 3,
            'settings' => 'null',
            'vault' => Crypt::encryptString('null'),
            'blob' => 'null',
        ]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->chunks[0]->status)->toBe(ChunkStatus::Completed);

        $row2 = DB::table('jp_invalid_docs')->where('id', 2)->first();
        expect($row2->settings)->toBeNull();
        expect($row2->vault)->toBeNull();
        expect($row2->blob)->toBeNull();

        // The JSON literal null decodes to PHP null, exactly like SQL NULL, and is
        // written back as SQL NULL as before; it is not an error.
        $row3 = DB::table('jp_invalid_docs')->where('id', 3)->first();
        expect($row3->settings)->toBeNull();
        expect($row3->vault)->toBeNull();
        expect($row3->blob)->toBeNull();

        expect(JpInvalidDoc::find(1)->settings)->toBe(['profile' => ['email' => 'x@example.test']]);
    });

    it('still refuses the JSON literal null on a NOT NULL array-cast column with the nullability message', function () {
        Schema::create('jp_invalid_required', function ($table) {
            $table->id();
            $table->text('settings');
        });
        DB::table('jp_invalid_required')->insert(['id' => 1, 'settings' => 'null']);

        config()->set('pii.sanitizers', [JpInvalidRequiredDoc::class => JpInvalidRequiredSanitizer::class]);
        config()->set('pii.models', [JpInvalidRequiredDoc::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureMessage)->toContain('the replacement value is null but the column is NOT NULL (nullability constraint)');
        expect(DB::table('jp_invalid_required')->where('id', 1)->value('settings'))->toBe('null');
    });
}
