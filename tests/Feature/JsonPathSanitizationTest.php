<?php

namespace {
    use Illuminate\Database\Eloquent\Casts\AsArrayObject;
    use Illuminate\Database\Eloquent\Casts\Attribute;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\Values\Json;

    class JsonPathCallLog
    {
        /** @var list<string> */
        public static array $calls = [];

        public static function log(string $entry, mixed $return): mixed
        {
            self::$calls[] = $entry;

            return $return;
        }
    }

    class JsonPathDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'array', 'vault' => 'encrypted:array'];
        }
    }

    class JsonPathDocSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'prefs' => Json::paths([
                    'contact->phone' => fn ($v) => JsonPathCallLog::log('phone:'.$v, 'PHONE-'.strlen($v)),
                    'contact->email' => 'REDACTED@example.test',
                    'emergency->*->name' => 'name',
                ]),
                'settings' => Json::paths([
                    'profile->email' => fn () => 'x@example.test',
                    'profile->address->city' => 'CITY-X',
                ]),
                'vault' => Json::paths(['ic' => fn () => '000000-00-0000']),
                'note' => fn () => 'NOTE',
            ];
        }
    }

    class JsonPathFlatSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['note' => fn () => 'NOTE', 'age' => fn () => 7];
        }
    }

    class JsonPathTable extends Sanitizer
    {
        public function fields(): array
        {
            return ['payload' => Json::paths(['user->name' => 'ANON'])];
        }
    }

    // Boot-failure fixtures: every model reads json_path_docs.

    class JsonPathIntDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;
    }

    class JsonPathIntSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['age' => Json::paths(['a' => 'X-VALUE'])];
        }
    }

    class JsonPathObjectCastDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'object'];
        }
    }

    class JsonPathCollectionCastDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'collection'];
        }
    }

    class JsonPathArrayObjectCastDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => AsArrayObject::class];
        }
    }

    class JsonPathJsonCastDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'json'];
        }
    }

    class JsonPathJsonUnicodeCastDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['settings' => 'json:unicode'];
        }
    }

    class JsonPathSettingsSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['settings' => Json::paths(['profile->email' => fn () => 'x@example.test'])];
        }
    }

    class JsonPathGetMutatorDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        protected function prefs(): Attribute
        {
            return Attribute::get(fn ($value) => $value);
        }
    }

    class JsonPathSetMutatorDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;

        public function setPrefsAttribute(mixed $value): void
        {
            $this->attributes['prefs'] = $value;
        }
    }

    class JsonPathPrefsSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['prefs' => Json::paths(['contact->phone' => 'X-VALUE'])];
        }
    }

    class JsonPathPlainDoc extends Model
    {
        protected $table = 'json_path_docs';

        protected $guarded = [];

        public $timestamps = false;
    }

    class JsonPathOverlapSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['prefs' => Json::paths(['a' => 'x', 'a->b' => 'y'])];
        }
    }

    class JsonPathBracketSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['prefs' => Json::paths(['a[0]' => 'x'])];
        }
    }

    class JsonPathNestedSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['prefs' => Json::paths(['a' => Json::paths(['b' => 'x'])])];
        }
    }

    class JsonPathEmptySanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['prefs' => Json::paths([])];
        }
    }

    class JsonPathMirrorSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['prefs' => Json::paths(['contact->phone' => 'X-VALUE'])];
        }

        public function mirrors(): array
        {
            return ['prefs' => ['other.col']];
        }
    }
}

namespace {
    use Illuminate\Database\Events\QueryExecuted;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    const JSON_PATH_ROW1_PREFS = '{"contact":{"phone":"0123456789","email":"a@b.c"},"emergency":[{"name":"Ali","rel":"brother"},{"name":null,"rel":"x"},{"rel":"y"}],"age":41,"vip":true,"score":2.5,"lang":"Zoë","meta":{},"tags":["a","b"]}';

    const JSON_PATH_ROW2_PREFS = '{ "theme" : "light", "emergency" : [] }';

    /**
     * A live log of every select against the data table recorded from now on.
     *
     * @return ArrayObject<int, string>
     */
    function jsonPathDataSelects(): ArrayObject
    {
        $selects = new ArrayObject;

        DB::listen(function ($query) use ($selects): void {
            $sql = strtolower(ltrim($query->sql));

            if (str_starts_with($sql, 'select') && str_contains($sql, 'from "json_path_docs"')) {
                $selects->append($query->sql);
            }
        });

        return $selects;
    }

    /** @return array<int, object> */
    function jsonPathSnapshot(): array
    {
        return DB::table('json_path_docs')->orderBy('id')->get()->all();
    }

    /**
     * A live log of every update statement recorded from now on.
     *
     * @return ArrayObject<int, QueryExecuted>
     */
    function jsonPathUpdates(): ArrayObject
    {
        $updates = new ArrayObject;

        DB::listen(function ($query) use ($updates): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'update')) {
                $updates->append($query);
            }
        });

        return $updates;
    }

    beforeEach(function () {
        JsonPathCallLog::$calls = [];

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Schema::create('json_path_docs', function ($table) {
            $table->id();
            $table->text('prefs')->nullable();
            $table->json('settings')->nullable();
            $table->text('vault')->nullable();
            $table->string('note');
            $table->integer('age');
        });

        DB::table('json_path_docs')->insert([
            'id' => 1,
            'prefs' => JSON_PATH_ROW1_PREFS,
            'settings' => json_encode(['profile' => ['email' => 'p@q.r', 'address' => ['city' => 'Ipoh', 'zip' => '30000']], 'theme' => 'dark', 'counts' => [1, 2, 3]]),
            'vault' => Crypt::encryptString(json_encode(['ic' => '900101-14-5678', 'note' => 'keep'])),
            'note' => 'n1',
            'age' => 1,
        ]);
        DB::table('json_path_docs')->insert([
            'id' => 2,
            'prefs' => JSON_PATH_ROW2_PREFS,
            'settings' => json_encode(['profile' => ['email' => null], 'theme' => 'x']),
            'vault' => null,
            'note' => 'n2',
            'age' => 2,
        ]);
        DB::table('json_path_docs')->insert(['id' => 3, 'prefs' => null, 'settings' => null, 'vault' => null, 'note' => 'n3', 'age' => 3]);
        DB::table('json_path_docs')->insert(['id' => 4, 'prefs' => '', 'settings' => null, 'vault' => null, 'note' => 'n4', 'age' => 4]);

        config()->set('pii.sanitizers', [JsonPathDoc::class => JsonPathDocSanitizer::class]);
        config()->set('pii.models', [JsonPathDoc::class]);
    });

    it('rewrites only the declared paths of an uncast text column (AC-4, R2.1, R2.2)', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();

        $raw = DB::table('json_path_docs')->where('id', 1)->value('prefs');
        $after = json_decode($raw, true);
        $name = $after['emergency'][0]['name'];

        expect($name)->toBeString()->not->toBe('')->not->toBe('Ali');

        $expected = json_decode(JSON_PATH_ROW1_PREFS, true);
        $expected['contact']['phone'] = 'PHONE-10';
        $expected['contact']['email'] = 'REDACTED@example.test';
        $expected['emergency'][0]['name'] = $name;

        expect($after)->toBe($expected);
        expect($after['emergency'][1]['name'])->toBeNull();
        expect($after['emergency'][2])->not->toHaveKey('name');

        expect($raw)->toContain('"meta":{}')->toContain('"score":2.5')->toContain('"lang":"Zoë"');
    });

    it('rewrites only the declared paths of an array-cast column (AC-4, R2.1, R2.2)', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect(JsonPathDoc::find(1)->settings)->toBe([
            'profile' => ['email' => 'x@example.test', 'address' => ['city' => 'CITY-X', 'zip' => '30000']],
            'theme' => 'dark',
            'counts' => [1, 2, 3],
        ]);
    });

    it('rewrites an encrypted:array column and keeps it encrypted (AC-4)', function () {
        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect(JsonPathDoc::find(1)->vault)->toBe(['ic' => '000000-00-0000', 'note' => 'keep']);

        $raw = DB::table('json_path_docs')->where('id', 1)->value('vault');

        expect($raw)->not->toContain('000000-00-0000');
        expect(json_decode(Crypt::decryptString($raw), true))->toBe(['ic' => '000000-00-0000', 'note' => 'keep']);
    });

    it('skips a path that is absent in one row and null in another without error (AC-4, R2.4)', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        foreach ($report->models[0]->chunks as $chunk) {
            expect($chunk->status)->toBe(ChunkStatus::Completed);
        }

        expect(DB::table('json_path_docs')->where('id', 2)->value('prefs'))->toBe(JSON_PATH_ROW2_PREFS);
        expect(JsonPathDoc::find(2)->settings)->toBe(['profile' => ['email' => null], 'theme' => 'x']);

        $row3 = DB::table('json_path_docs')->where('id', 3)->first();
        expect($row3->prefs)->toBeNull();
        expect($row3->settings)->toBeNull();
        expect($row3->vault)->toBeNull();

        expect(DB::table('json_path_docs')->where('id', 4)->value('prefs'))->toBe('');

        expect(JsonPathCallLog::$calls)->toBe(['phone:0123456789']);
    });

    it('does not inflate the changed-count for skipped rows', function () {
        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->models[0]->columnCounts['prefs'])->toBe(1);
    });

    it('rewrites a pii.tables string carrier', function () {
        Schema::create('json_path_payloads', function ($table) {
            $table->id();
            $table->text('payload');
        });
        DB::table('json_path_payloads')->insert(['id' => 1, 'payload' => '{"user":{"name":"Siti","id":7}}']);

        config()->set('pii.models', []);
        config()->set('pii.tables', ['json_path_payloads' => JsonPathTable::class]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect(DB::table('json_path_payloads')->where('id', 1)->value('payload'))->toBe('{"user":{"name":"ANON","id":7}}');
    });

    it('rolls the chunk back on a malformed document with a value-free message', function () {
        DB::table('json_path_docs')->insert(['id' => 5, 'prefs' => '{"contact":', 'settings' => null, 'vault' => null, 'note' => 'n5', 'age' => 5]);

        $before = jsonPathSnapshot();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(InvalidReplacementValueException::class);
        expect($chunk->failureMessage)->toContain('$prefs: ');
        expect($chunk->failureMessage)->toContain('The column value is not a valid JSON document, so its declared paths cannot be rewritten.');
        expect(jsonPathSnapshot())->toEqual($before);
    });

    it('fails every path-map boot check before any row is read', function (string $model, string $sanitizer, string $exception, string $fragment) {
        config()->set('pii.sanitizers', [$model => $sanitizer]);
        config()->set('pii.models', [$model]);

        $before = jsonPathSnapshot();

        $selects = jsonPathDataSelects();

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow($exception, $fragment);

        expect($selects->getArrayCopy())->toBe([]);
        expect(jsonPathSnapshot())->toEqual($before);
    })->with([
        'integer column' => [JsonPathIntDoc::class, JsonPathIntSanitizer::class, InvalidConfigurationException::class, 'its column type family is "integer", not json or string.'],
        'object cast' => [JsonPathObjectCastDoc::class, JsonPathSettingsSanitizer::class, InvalidConfigurationException::class, 'its cast "object" is not one of: none, array, json, json:unicode, encrypted:array, encrypted:json.'],
        'collection cast' => [JsonPathCollectionCastDoc::class, JsonPathSettingsSanitizer::class, InvalidConfigurationException::class, 'its cast "collection" is not one of:'],
        'AsArrayObject cast' => [JsonPathArrayObjectCastDoc::class, JsonPathSettingsSanitizer::class, InvalidConfigurationException::class, 'is not one of: none, array'],
        'get mutator' => [JsonPathGetMutatorDoc::class, JsonPathPrefsSanitizer::class, InvalidConfigurationException::class, 'it has a get or set mutator.'],
        'set mutator' => [JsonPathSetMutatorDoc::class, JsonPathPrefsSanitizer::class, InvalidConfigurationException::class, 'it has a get or set mutator.'],
        'overlapping paths' => [JsonPathPlainDoc::class, JsonPathOverlapSanitizer::class, InvalidArgumentException::class, 'overlap'],
        'bracket path' => [JsonPathPlainDoc::class, JsonPathBracketSanitizer::class, InvalidArgumentException::class, '->0'],
        'nested path map' => [JsonPathPlainDoc::class, JsonPathNestedSanitizer::class, InvalidArgumentException::class, 'nesting'],
        'empty path map' => [JsonPathPlainDoc::class, JsonPathEmptySanitizer::class, InvalidArgumentException::class, 'at least one path'],
        'mirrors opt-in' => [JsonPathPlainDoc::class, JsonPathMirrorSanitizer::class, InvalidConfigurationException::class, 'is not a Keyed value'],
    ]);

    it('reports the full integer-column message byte-exactly', function () {
        config()->set('pii.sanitizers', [JsonPathIntDoc::class => JsonPathIntSanitizer::class]);
        config()->set('pii.models', [JsonPathIntDoc::class]);

        expect(fn () => app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10)))
            ->toThrow(InvalidConfigurationException::class, '[laravel-pii-sanitizer] JsonPathIntDoc::$age on table "json_path_docs" cannot take a Json::paths() definition in JsonPathIntSanitizer::fields(): its column type family is "integer", not json or string.');
    });

    it('records data selects during a successful run (listener positive control)', function () {
        $selects = jsonPathDataSelects();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect(count($selects))->toBeGreaterThan(0);
    });

    it('accepts the json and json:unicode casts', function (string $model) {
        config()->set('pii.sanitizers', [$model => JsonPathSettingsSanitizer::class]);
        config()->set('pii.models', [$model]);

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($model::find(1)->settings['profile']['email'])->toBe('x@example.test');
    })->with([
        'json' => [JsonPathJsonCastDoc::class],
        'json:unicode' => [JsonPathJsonUnicodeCastDoc::class],
    ]);

    it('keeps v1\'s batched write shape for a flat-only run (AC-13)', function () {
        config()->set('pii.sanitizers', [JsonPathDoc::class => JsonPathFlatSanitizer::class]);

        $log = jsonPathUpdates();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $updates = $log->getArrayCopy();

        expect($updates)->toHaveCount(1);

        $whenPairs = trim(str_repeat('WHEN ? THEN ? ', 4));

        $expected = sprintf(
            'UPDATE "json_path_docs" SET "note" = CASE "id" %s END, "age" = CASE "id" %s END WHERE "id" IN (%s)',
            $whenPairs,
            $whenPairs,
            implode(', ', array_fill(0, 4, '?'))
        );

        expect($updates[0]->sql)->toBe($expected);
        expect(count($updates[0]->bindings))->toBe(4 * 2 * 2 + 4);
    });

    it('writes one whole-document value per row per column through the batched path', function () {
        $log = jsonPathUpdates();

        app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $updates = $log->getArrayCopy();

        expect($updates)->toHaveCount(1);
        expect($updates[0]->sql)->toStartWith('UPDATE "json_path_docs" SET "prefs" = CASE "id" WHEN ? THEN ?');
        expect($updates[0]->sql)->toContain('"settings" = CASE "id"')->toContain('"vault" = CASE "id"')->toContain('"note" = CASE "id"');
    });
}
