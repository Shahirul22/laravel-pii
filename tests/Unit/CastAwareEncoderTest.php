<?php

namespace {
    use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
    use Illuminate\Database\Eloquent\Casts\Attribute;
    use Illuminate\Database\Eloquent\Model;

    enum CastEncStatus: string
    {
        case Active = 'active';
        case Banned = 'banned';
    }

    class CastEncMultiColumnCast implements CastsAttributes
    {
        public function get($model, string $key, $value, array $attributes): mixed
        {
            return $attributes[$key] ?? null;
        }

        public function set($model, string $key, $value, array $attributes): mixed
        {
            return [$key => $value, 'name' => 'touched-'.$value];
        }
    }

    class CastEncThrowingCast implements CastsAttributes
    {
        public function get($model, string $key, $value, array $attributes): mixed
        {
            return $value;
        }

        public function set($model, string $key, $value, array $attributes): mixed
        {
            throw new InvalidArgumentException('cannot encode '.$value);
        }
    }

    class CastEncModel extends Model
    {
        protected $table = 'cast_enc_rows';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return [
                'ssn' => 'encrypted',
                'meta' => 'array',
                'status' => CastEncStatus::class,
            ];
        }

        public function setNicknameAttribute($value): void
        {
            $this->attributes['nickname'] = strtoupper((string) $value);
        }

        protected function handle(): Attribute
        {
            return Attribute::make(set: fn ($value) => '@'.$value);
        }
    }

    class CastEncMultiModel extends Model
    {
        protected $table = 'cast_enc_rows';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return [
                'ssn' => CastEncMultiColumnCast::class,
            ];
        }
    }

    class CastEncThrowingModel extends Model
    {
        protected $table = 'cast_enc_rows';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return [
                'ssn' => CastEncThrowingCast::class,
            ];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\CastAwareEncoder;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsupportedCastException;

    beforeEach(function () {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Schema::create('cast_enc_rows', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('ssn')->nullable();
            $table->text('meta')->nullable();
            $table->string('status')->nullable();
            $table->string('nickname')->nullable();
            $table->string('handle')->nullable();
        });
    });

    it('classifies exactly the cast-bearing columns, in input order', function () {
        $encoder = app(CastAwareEncoder::class);
        $model = new CastEncModel;

        expect($encoder->classify($model, ['name', 'ssn', 'meta', 'status', 'nickname', 'handle']))
            ->toBe(['ssn', 'meta', 'status', 'nickname', 'handle']);

        expect($encoder->classify($model, ['name']))->toBe([]);
    });

    it('encodes an encrypted-cast column into ciphertext decryptable back to the original', function () {
        $encoder = app(CastAwareEncoder::class);
        $row = (new CastEncModel)->newFromBuilder(['id' => 1, 'name' => 'Bob', 'ssn' => Crypt::encryptString('000-00-0000'), 'meta' => null, 'status' => null, 'nickname' => null, 'handle' => null]);

        $result = $encoder->encode($row, 'ssn', '111-22-3333');

        expect($result)->not->toBe('111-22-3333');
        expect(Crypt::decryptString($result))->toBe('111-22-3333');
    });

    it('encodes an array-cast column to its JSON representation', function () {
        $encoder = app(CastAwareEncoder::class);
        $row = (new CastEncModel)->newFromBuilder(['id' => 1, 'name' => 'Bob', 'ssn' => Crypt::encryptString('x'), 'meta' => json_encode(['a' => 0]), 'status' => null, 'nickname' => null, 'handle' => null]);

        $result = $encoder->encode($row, 'meta', ['a' => 1]);

        expect($result)->toBe('{"a":1}');
    });

    it('encodes a backed-enum cast column to its underlying scalar', function () {
        $encoder = app(CastAwareEncoder::class);
        $row = (new CastEncModel)->newFromBuilder(['id' => 1, 'name' => 'Bob', 'ssn' => Crypt::encryptString('x'), 'meta' => null, 'status' => 'active', 'nickname' => null, 'handle' => null]);

        $result = $encoder->encode($row, 'status', CastEncStatus::Banned);

        expect($result)->toBe('banned');
    });

    it('runs a legacy set-mutator and a modern Attribute set-mutator', function () {
        $encoder = app(CastAwareEncoder::class);
        $row = (new CastEncModel)->newFromBuilder(['id' => 1, 'name' => 'Bob', 'ssn' => Crypt::encryptString('x'), 'meta' => null, 'status' => null, 'nickname' => null, 'handle' => null]);

        expect($encoder->encode($row, 'nickname', 'bob'))->toBe('BOB');
        expect($encoder->encode($row, 'handle', 'bob'))->toBe('@bob');
    });

    it('does not mutate the source row or touch the database', function () {
        $encoder = app(CastAwareEncoder::class);
        $row = (new CastEncModel)->newFromBuilder(['id' => 1, 'name' => 'Bob', 'ssn' => Crypt::encryptString('000-00-0000'), 'meta' => json_encode(['a' => 0]), 'status' => 'active', 'nickname' => null, 'handle' => null]);

        $before = $row->getAttributes();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $encoder->encode($row, 'ssn', '111-22-3333');

        expect(DB::getQueryLog())->toBe([]);
        expect($row->getAttributes())->toBe($before);
        expect($row->isDirty())->toBeFalse();
    });

    it('refuses a multi-column class cast that writes a second attribute', function () {
        $encoder = app(CastAwareEncoder::class);
        $row = (new CastEncMultiModel)->newFromBuilder(['id' => 1, 'name' => 'Bob', 'ssn' => 'x', 'meta' => null, 'status' => null, 'nickname' => null, 'handle' => null]);

        try {
            $encoder->encode($row, 'ssn', 'x');
            expect(false)->toBeTrue('expected UnsupportedCastException');
        } catch (UnsupportedCastException $e) {
            expect($e->getMessage())->toContain('CastEncMultiModel');
            expect($e->getMessage())->toContain('ssn');
            expect($e->getMessage())->toContain('cast_enc_rows');
            expect($e->getMessage())->toContain('name');
        }
    });

    it('refuses an encode failure without leaking the value in the message', function () {
        $encoder = app(CastAwareEncoder::class);
        $row = (new CastEncThrowingModel)->newFromBuilder(['id' => 1, 'name' => 'Bob', 'ssn' => 'x', 'meta' => null, 'status' => null, 'nickname' => null, 'handle' => null]);

        try {
            $encoder->encode($row, 'ssn', 'SECRET-PLAINTEXT');
            expect(false)->toBeTrue('expected UnsupportedCastException');
        } catch (UnsupportedCastException $e) {
            expect($e->getMessage())->toContain('ssn');
            expect($e->getMessage())->toContain(InvalidArgumentException::class);
            expect($e->getMessage())->not->toContain('SECRET-PLAINTEXT');
            expect($e->getMessage())->not->toContain('cannot encode');
        }
    });
}
