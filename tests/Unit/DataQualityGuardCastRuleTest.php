<?php

namespace {
    use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
    use Illuminate\Database\Eloquent\Casts\AsArrayObject;
    use Illuminate\Database\Eloquent\Casts\AsCollection;
    use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
    use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
    use Illuminate\Database\Eloquent\Casts\Attribute;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    enum DgcrStatus: string
    {
        case Open = 'open';
    }

    enum DgcrSize
    {
        case Small;
    }

    class DgcrUpperCast implements CastsAttributes
    {
        public function get(Model $model, string $key, mixed $value, array $attributes): mixed
        {
            return $value;
        }

        public function set(Model $model, string $key, mixed $value, array $attributes): mixed
        {
            return strtoupper((string) $value);
        }
    }

    class DgcrModel extends Model
    {
        protected $table = 'dgcr_rows';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return [
                'c_int' => 'int',
                'c_integer' => 'integer',
                'c_real' => 'real',
                'c_float' => 'float',
                'c_double' => 'double',
                'c_decimal' => 'decimal:2',
                'c_string' => 'string',
                'c_bool' => 'bool',
                'c_boolean' => 'boolean',
                'c_date' => 'date',
                'c_datetime' => 'datetime',
                'c_datetime_format' => 'datetime:Y-m-d',
                'c_immutable_date' => 'immutable_date',
                'c_immutable_datetime' => 'immutable_datetime:Y-m-d H:i',
                'c_timestamp' => 'timestamp',
                'c_backed_enum' => DgcrStatus::class,
                'c_unit_enum' => DgcrSize::class,
                'c_array' => 'array',
                'c_json' => 'json',
                'c_json_unicode' => 'json:unicode',
                'c_object' => 'object',
                'c_collection' => 'collection',
                'c_encrypted' => 'encrypted',
                'c_encrypted_array' => 'encrypted:array',
                'c_encrypted_collection' => 'encrypted:collection',
                'c_encrypted_object' => 'encrypted:object',
                'c_hashed' => 'hashed',
                'c_as_array_object' => AsArrayObject::class,
                'c_as_collection' => AsCollection::class,
                'c_as_encrypted_array_object' => AsEncryptedArrayObject::class,
                'c_as_encrypted_collection' => AsEncryptedCollection::class,
                'c_custom' => DgcrUpperCast::class,
                'c_custom_with_argument' => DgcrUpperCast::class.':upper',
            ];
        }

        public function setLegacyAttribute(mixed $value): void
        {
            $this->attributes['legacy'] = strtoupper((string) $value);
        }

        protected function modern(): Attribute
        {
            return Attribute::make(set: fn (mixed $value) => strtoupper((string) $value));
        }

        protected function readOnly(): Attribute
        {
            return Attribute::make(get: fn (mixed $value) => strtoupper((string) $value));
        }
    }

    class DgcrSanitizer extends Sanitizer
    {
        public static string $column = 'c_int';

        public function fields(): array
        {
            return [static::$column => 'word'];
        }

        public function categorical(): array
        {
            return [static::$column];
        }
    }
}

namespace {

    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\DataQualityGuard;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidCategoricalColumnException;

    // BUG-11 (round 2): categorical() refuses only a cast or mutator that would
    // change the meaning of a raw stored value by encoding it again.

    beforeEach(function () {
        Schema::create('dgcr_rows', function ($table) {
            $table->id();
        });
    });

    it('accepts a categorical column whose cast stores a raw sampled value unchanged in meaning', function (string $column) {
        DgcrSanitizer::$column = $column;

        app(DataQualityGuard::class)->assertValid(new DgcrSanitizer, DgcrModel::class);

        expect(true)->toBeTrue();
    })->with([
        'c_int', 'c_integer', 'c_real', 'c_float', 'c_double', 'c_decimal', 'c_string', 'c_bool', 'c_boolean',
        'c_date', 'c_datetime', 'c_datetime_format', 'c_immutable_date', 'c_immutable_datetime', 'c_timestamp',
        'c_backed_enum', 'c_unit_enum',
        'get accessor only' => 'read_only',
    ]);

    it('refuses a categorical column whose cast or mutator would encode a raw sampled value again', function (string $column) {
        DgcrSanitizer::$column = $column;

        expect(fn () => app(DataQualityGuard::class)->assertValid(new DgcrSanitizer, DgcrModel::class))
            ->toThrow(InvalidCategoricalColumnException::class, InvalidCategoricalColumnException::castBearing(DgcrModel::class, $column, DgcrSanitizer::class)->getMessage());
    })->with([
        'c_array', 'c_json', 'c_json_unicode', 'c_object', 'c_collection',
        'c_encrypted', 'c_encrypted_array', 'c_encrypted_collection', 'c_encrypted_object', 'c_hashed',
        'c_as_array_object', 'c_as_collection', 'c_as_encrypted_array_object', 'c_as_encrypted_collection',
        'c_custom', 'c_custom_with_argument',
        'legacy set mutator' => 'legacy',
        'Attribute set closure' => 'modern',
    ]);
}
