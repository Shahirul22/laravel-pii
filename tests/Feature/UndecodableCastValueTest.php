<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class UdcUser extends Model
    {
        protected $table = 'udc_users';

        protected $guarded = [];

        public $timestamps = false;

        protected $casts = ['ssn' => 'encrypted', 'prefs' => 'encrypted:array'];
    }

    class UdcUserSanitizer extends Sanitizer
    {
        /** @var array<string, mixed> */
        public static array $fields = [];

        public function fields(): array
        {
            return static::$fields;
        }
    }
}

namespace {

    use Illuminate\Contracts\Encryption\DecryptException;
    use Illuminate\Encryption\Encrypter;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsupportedCastException;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;
    use Shahirul22\LaravelPiiSanitizer\Values\Format;

    // BUG-8: after importing a production dump, an encrypted cast holds
    // ciphertext made with another APP_KEY, which cannot be decrypted here.

    beforeEach(function () {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $foreign = new Encrypter(random_bytes(32), 'aes-256-cbc');

        Schema::create('udc_users', function ($table) {
            $table->id();
            $table->text('ssn')->nullable();
            $table->text('prefs')->nullable();
        });

        DB::table('udc_users')->insert([
            ['ssn' => $foreign->encryptString('123-45-6789'), 'prefs' => $foreign->encryptString('{"a":1}')],
            ['ssn' => $foreign->encryptString('987-65-4321'), 'prefs' => $foreign->encryptString('{"a":2}')],
        ]);

        config()->set('pii.sanitizers', [UdcUser::class => UdcUserSanitizer::class]);
        config()->set('pii.models', [UdcUser::class]);
    });

    it('replaces an undecryptable value when the definition ignores the current value', function (array $fields) {
        UdcUserSanitizer::$fields = $fields;

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->columnCounts)->toBe(array_fill_keys(array_keys($fields), 2));

        foreach (UdcUser::query()->orderBy('id')->get() as $user) {
            if (array_key_exists('ssn', $fields)) {
                expect($user->ssn)->toBeString()->not->toBe('123-45-6789')->not->toBe('987-65-4321');
            }

            if (array_key_exists('prefs', $fields)) {
                expect($user->prefs)->toBe(['redacted' => true]);
            }
        }
    })->with([
        'static value' => [['ssn' => '999-99-9999']],
        'Faker formatter name' => [['ssn' => 'safeEmail']],
        'static array on an encrypted:array cast' => [['prefs' => ['redacted' => true]]],
    ]);

    it('fails with a named error that names the model, column and cast when the definition uses the current value', function (string $kind) {
        UdcUserSanitizer::$fields = ['ssn' => $kind === 'closure' ? fn ($value) => $value.'-x' : Format::keepLast(2)];

        $before = DB::table('udc_users')->orderBy('id')->pluck('ssn')->all();

        $report = app(SanitizationRunner::class)->run(new RunOptions(chunkSize: 10));

        $chunk = $report->models[0]->chunks[0];

        expect($chunk->status)->toBe(ChunkStatus::RolledBack);
        expect($chunk->failureClass)->toBe(UnsupportedCastException::class);
        expect($chunk->failureMessage)->toBe(UnsupportedCastException::undecodableValue(UdcUser::class, 'ssn', 'udc_users', 'encrypted', DecryptException::class)->getMessage());
        expect($chunk->failureMessage)->not->toContain($before[0]);
        expect(DB::table('udc_users')->orderBy('id')->pluck('ssn')->all())->toBe($before);
    })->with([
        'closure' => ['closure'],
        'value generator' => ['value generator'],
    ]);
}
