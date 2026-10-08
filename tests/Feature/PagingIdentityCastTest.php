<?php

namespace {
    use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
    use Illuminate\Database\Eloquent\Casts\Attribute;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class PicPrefixCast implements CastsAttributes
    {
        public function get(Model $model, string $key, mixed $value, array $attributes): mixed
        {
            return $value === null ? null : 'u'.$value;
        }

        public function set(Model $model, string $key, mixed $value, array $attributes): mixed
        {
            return $value === null ? null : (int) ltrim((string) $value, 'u');
        }
    }

    class PicCastKeyUser extends Model
    {
        protected $table = 'pic_cast_key_users';

        protected $guarded = [];

        public $timestamps = false;

        protected function casts(): array
        {
            return ['id' => PicPrefixCast::class];
        }
    }

    class PicAccessorKeyUser extends Model
    {
        protected $table = 'pic_accessor_key_users';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;

        protected $keyType = 'string';

        protected function id(): Attribute
        {
            return Attribute::make(get: fn ($value) => strtoupper((string) $value));
        }
    }

    /** Keyless table, so the identity is source 2, 3 or 4 — never the model key. */
    class PicCodeRow extends Model
    {
        protected $table = 'pic_code_rows';

        protected $guarded = [];

        public $timestamps = false;

        protected function code(): Attribute
        {
            return Attribute::make(get: fn ($value) => strtoupper((string) $value));
        }
    }

    class PicCompositeRow extends Model
    {
        protected $table = 'pic_composite_rows';

        protected $guarded = [];

        public $timestamps = false;

        protected function memberRef(): Attribute
        {
            return Attribute::make(get: fn ($value) => 'M-'.strtoupper((string) $value));
        }
    }

    class PicNameSanitizer extends Sanitizer
    {
        /** @var list<string> */
        public static array $seen = [];

        public function fields(): array
        {
            return [
                'name' => function ($value, $faker, $row) {
                    self::$seen[] = (string) $value;

                    // A paging regression can loop forever on these tables;
                    // stop it so the test fails instead of hanging.
                    if (count(self::$seen) > 50) {
                        throw new RuntimeException('runaway paging');
                    }

                    return 'clean-'.$value;
                },
            ];
        }
    }

    class PicDeclaredSanitizer extends PicNameSanitizer
    {
        public function pagingKey(): array
        {
            return ['code'];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\PagingKeyResolver;
    use Shahirul22\LaravelPiiSanitizer\RunOptions;
    use Shahirul22\LaravelPiiSanitizer\RunReport;
    use Shahirul22\LaravelPiiSanitizer\SanitizationRunner;

    beforeEach(function () {
        PicNameSanitizer::$seen = [];
    });

    /**
     * @param  class-string  $model
     * @param  class-string  $sanitizer
     */
    function picRun(string $model, string $sanitizer, int $chunkSize): RunReport
    {
        config()->set('pii.sanitizers', [$model => $sanitizer]);
        config()->set('pii.models', [$model]);

        return app(SanitizationRunner::class)->run(new RunOptions(chunkSize: $chunkSize));
    }

    it('writes and pages every row when the primary key has a cast (BUG-35)', function () {
        Schema::create('pic_cast_key_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        foreach (['ann', 'bob', 'cat', 'dan', 'eve'] as $name) {
            DB::table('pic_cast_key_users')->insert(['name' => $name]);
        }

        $report = picRun(PicCastKeyUser::class, PicNameSanitizer::class, 2);

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(5);
        expect($report->models[0]->chunks)->toHaveCount(3);
        expect(PicNameSanitizer::$seen)->toBe(['ann', 'bob', 'cat', 'dan', 'eve']);

        // The stored rows, not only the report.
        expect(DB::table('pic_cast_key_users')->orderBy('id')->pluck('name')->all())
            ->toBe(['clean-ann', 'clean-bob', 'clean-cat', 'clean-dan', 'clean-eve']);

        // The report carries the raw stored key, not the cast value.
        expect($report->models[0]->chunks[0]->firstKey)->toBe(1);
        expect($report->models[0]->chunks[2]->lastKey)->toBe(5);
    });

    it('writes and pages every row when a string primary key has an accessor (BUG-35)', function () {
        Schema::create('pic_accessor_key_users', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
        });

        foreach (['a1' => 'ann', 'b2' => 'bob', 'c3' => 'cat', 'd4' => 'dan', 'e5' => 'eve'] as $id => $name) {
            DB::table('pic_accessor_key_users')->insert(['id' => $id, 'name' => $name]);
        }

        $report = picRun(PicAccessorKeyUser::class, PicNameSanitizer::class, 2);

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(5);
        expect(PicNameSanitizer::$seen)->toBe(['ann', 'bob', 'cat', 'dan', 'eve']);
        expect(DB::table('pic_accessor_key_users')->orderBy('id')->pluck('name')->all())
            ->toBe(['clean-ann', 'clean-bob', 'clean-cat', 'clean-dan', 'clean-eve']);
        expect($report->models[0]->chunks[0]->firstKey)->toBe('a1');
    });

    it('writes and pages every row when a unique-index identity (source 2) has an accessor (BUG-29)', function () {
        Schema::create('pic_code_rows', function ($table) {
            $table->string('code')->unique();
            $table->string('name');
        });

        foreach (['a' => 'ann', 'b' => 'bob', 'c' => 'cat', 'd' => 'dan', 'e' => 'eve'] as $code => $name) {
            DB::table('pic_code_rows')->insert(['code' => $code, 'name' => $name]);
        }

        expect(app(PagingKeyResolver::class)->resolve(new PicCodeRow, new PicNameSanitizer)->source)->toBe('unique');

        $report = picRun(PicCodeRow::class, PicNameSanitizer::class, 2);

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(5);
        expect($report->models[0]->chunks)->toHaveCount(3);
        expect(PicNameSanitizer::$seen)->toBe(['ann', 'bob', 'cat', 'dan', 'eve']);
        expect(DB::table('pic_code_rows')->orderBy('code')->pluck('name')->all())
            ->toBe(['clean-ann', 'clean-bob', 'clean-cat', 'clean-dan', 'clean-eve']);
    });

    it('writes and pages every row when a declared pagingKey() (source 3) has an accessor (BUG-29)', function () {
        Schema::create('pic_code_rows', function ($table) {
            $table->string('code');
            $table->string('name');
        });

        foreach (['a' => 'ann', 'b' => 'bob', 'c' => 'cat', 'd' => 'dan', 'e' => 'eve'] as $code => $name) {
            DB::table('pic_code_rows')->insert(['code' => $code, 'name' => $name]);
        }

        expect(app(PagingKeyResolver::class)->resolve(new PicCodeRow, new PicDeclaredSanitizer)->source)->toBe('declared');

        $report = picRun(PicCodeRow::class, PicDeclaredSanitizer::class, 2);

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(5);
        expect(PicNameSanitizer::$seen)->toBe(['ann', 'bob', 'cat', 'dan', 'eve']);
        expect(DB::table('pic_code_rows')->orderBy('code')->pluck('name')->all())
            ->toBe(['clean-ann', 'clean-bob', 'clean-cat', 'clean-dan', 'clean-eve']);
    });

    it('writes and pages every row when a fallback identity (source 4) has an accessor (BUG-29)', function () {
        Schema::create('pic_code_rows', function ($table) {
            $table->string('code');
            $table->string('name');
        });

        foreach (['a' => 'ann', 'b' => 'bob', 'c' => 'cat', 'd' => 'dan', 'e' => 'eve'] as $code => $name) {
            DB::table('pic_code_rows')->insert(['code' => $code, 'name' => $name]);
        }

        $key = app(PagingKeyResolver::class)->resolve(new PicCodeRow, new PicNameSanitizer);
        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['code']);

        $report = picRun(PicCodeRow::class, PicNameSanitizer::class, 2);

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(5);
        expect(PicNameSanitizer::$seen)->toBe(['ann', 'bob', 'cat', 'dan', 'eve']);
        expect(DB::table('pic_code_rows')->orderBy('code')->pluck('name')->all())
            ->toBe(['clean-ann', 'clean-bob', 'clean-cat', 'clean-dan', 'clean-eve']);
    });

    it('writes and pages every row of a composite key when one key column has an accessor (BUG-29)', function () {
        Schema::create('pic_composite_rows', function ($table) {
            $table->unsignedBigInteger('org_id');
            $table->string('member_ref');
            $table->string('name');
            $table->primary(['org_id', 'member_ref']);
        });

        $expected = [];

        foreach ([1, 2] as $org) {
            foreach (['a', 'b', 'c'] as $ref) {
                $name = "o{$org}{$ref}";
                DB::table('pic_composite_rows')->insert(['org_id' => $org, 'member_ref' => $ref, 'name' => $name]);
                $expected[] = $name;
            }
        }

        $report = picRun(PicCompositeRow::class, PicNameSanitizer::class, 4);

        expect($report->failed())->toBeFalse();
        expect($report->models[0]->rowsSanitized())->toBe(6);
        expect($report->models[0]->chunks)->toHaveCount(2);
        expect(PicNameSanitizer::$seen)->toBe($expected);
        expect(DB::table('pic_composite_rows')->orderBy('org_id')->orderBy('member_ref')->pluck('name')->all())
            ->toBe(array_map(fn (string $name): string => 'clean-'.$name, $expected));
        expect($report->models[0]->chunks[1]->firstKey)->toBe(['org_id' => 2, 'member_ref' => 'b']);
    });
}
