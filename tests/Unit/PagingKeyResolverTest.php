<?php

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class PkIdModel extends Model
    {
        protected $table = 'pk_id_models';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PkIdModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PkCompositeModel extends Model
    {
        protected $table = 'pk_composite_models';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PkCompositeModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PkUuidModel extends Model
    {
        protected $table = 'pk_uuid_models';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;

        protected $keyType = 'string';
    }

    class PkUuidModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PkUniqueModel extends Model
    {
        protected $table = 'pk_unique_models';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PkUniqueModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }
    }

    class PkDeclaredModel extends Model
    {
        protected $table = 'pk_declared_models';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PkDeclaredModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }

        public function pagingKey(): array
        {
            return ['ref'];
        }
    }

    class PkDeclaredIgnoredModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email' => 'safeEmail'];
        }

        public function pagingKey(): array
        {
            return ['email'];
        }
    }

    class PkKeylessModel extends Model
    {
        protected $table = 'pk_keyless_models';

        protected $guarded = [];

        public $timestamps = false;
    }

    class PkKeylessModelSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return ['email', 'bio'];
        }

        public function fields2(): array
        {
            return [];
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;
    use Shahirul22\LaravelPiiSanitizer\PagingKey;
    use Shahirul22\LaravelPiiSanitizer\PagingKeyResolver;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\TableRow;

    it('resolves the schema primary key as source primary', function () {
        Schema::create('pk_id_models', function ($table) {
            $table->id();
            $table->string('email');
        });

        $resolver = app(PagingKeyResolver::class);
        $key = $resolver->resolve(new PkIdModel, new PkIdModelSanitizer);

        expect($key)->toBeInstanceOf(PagingKey::class);
        expect($key->columns)->toBe(['id']);
        expect($key->source)->toBe('primary');
    });

    it('resolves a composite primary key in PK order, even when the model default key does not exist', function () {
        Schema::create('pk_composite_models', function ($table) {
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('member_id');
            $table->string('email');
            $table->primary(['org_id', 'member_id']);
        });

        $resolver = app(PagingKeyResolver::class);
        $key = $resolver->resolve(new PkCompositeModel, new PkCompositeModelSanitizer);

        expect($key->columns)->toBe(['org_id', 'member_id']);
        expect($key->source)->toBe('primary');
    });

    it('resolves a UUID/string primary key as source primary', function () {
        Schema::create('pk_uuid_models', function ($table) {
            $table->uuid('id')->primary();
            $table->string('email');
        });

        $resolver = app(PagingKeyResolver::class);
        $key = $resolver->resolve(new PkUuidModel, new PkUuidModelSanitizer);

        expect($key->columns)->toBe(['id']);
        expect($key->source)->toBe('primary');
    });

    it('falls back to a NOT NULL unique index disjoint from fields() when there is no primary key', function () {
        Schema::create('pk_unique_models', function ($table) {
            $table->string('code');
            $table->string('email');
            $table->unique('code');
        });

        for ($i = 0; $i < 3; $i++) {
            DB::table('pk_unique_models')->insert(['code' => "code-{$i}", 'email' => "e{$i}@x.test"]);
        }

        $resolver = app(PagingKeyResolver::class);
        $key = $resolver->resolve(new PkUniqueModel, new PkUniqueModelSanitizer);

        expect($key->columns)->toBe(['code']);
        expect($key->source)->toBe('unique');
    });

    it('honors a declared pagingKey() when no schema source resolves and the declared columns are provably unique', function () {
        Schema::create('pk_declared_models', function ($table) {
            $table->string('ref');
            $table->string('code');
            $table->string('email');
        });

        DB::table('pk_declared_models')->insert(['ref' => 'r1', 'code' => 'dup', 'email' => 'a@x.test']);
        DB::table('pk_declared_models')->insert(['ref' => 'r2', 'code' => 'dup', 'email' => 'b@x.test']);

        $resolver = app(PagingKeyResolver::class);
        $key = $resolver->resolve(new PkDeclaredModel, new PkDeclaredModelSanitizer);

        expect($key->columns)->toBe(['ref']);
        expect($key->source)->toBe('declared');
    });

    it('ignores a declared pagingKey() that overlaps fields() and falls through to the fallback source', function () {
        Schema::create('pk_declared_models', function ($table) {
            $table->string('ref');
            $table->string('code');
            $table->string('email');
        });

        DB::table('pk_declared_models')->insert(['ref' => 'r1', 'code' => 'c1', 'email' => 'a@x.test']);
        DB::table('pk_declared_models')->insert(['ref' => 'r2', 'code' => 'c2', 'email' => 'b@x.test']);

        $resolver = app(PagingKeyResolver::class);
        $key = $resolver->resolve(new PkDeclaredModel, new PkDeclaredIgnoredModelSanitizer);

        expect($key->source)->toBe('fallback');
        expect($key->columns)->not->toBe(['email']);
    });

    it('resolves a keyless table via the NOT NULL, non-json/binary/decimal fallback, excluding fields()', function () {
        Schema::create('pk_keyless_models', function ($table) {
            $table->string('first');
            $table->string('last');
            $table->decimal('score');
            $table->string('email');
            $table->text('bio')->nullable();
        });

        for ($i = 0; $i < 3; $i++) {
            DB::table('pk_keyless_models')->insert([
                'first' => "f{$i}",
                'last' => "l{$i}",
                'score' => 1.5,
                'email' => "e{$i}@x.test",
                'bio' => null,
            ]);
        }

        $sanitizer = new class extends Sanitizer
        {
            public function fields(): array
            {
                return ['email' => 'safeEmail', 'bio' => null];
            }
        };

        $resolver = app(PagingKeyResolver::class);
        $key = $resolver->resolve(new PkKeylessModel, $sanitizer);

        expect($key->columns)->toBe(['first', 'last']);
        expect($key->source)->toBe('fallback');
    });

    it('throws UnpageableTableException when two rows are identical across every eligible column', function () {
        Schema::create('pk_keyless_models', function ($table) {
            $table->string('first');
            $table->string('last');
            $table->string('email');
        });

        DB::table('pk_keyless_models')->insert(['first' => 'dup', 'last' => 'dup', 'email' => 'a@x.test']);
        DB::table('pk_keyless_models')->insert(['first' => 'dup', 'last' => 'dup', 'email' => 'b@x.test']);

        $sanitizer = new class extends Sanitizer
        {
            public function fields(): array
            {
                return ['email' => 'safeEmail'];
            }
        };

        $resolver = app(PagingKeyResolver::class);

        expect(fn () => $resolver->resolve(new PkKeylessModel, $sanitizer))
            ->toThrow(UnpageableTableException::class, 'pk_keyless_models');
    });

    it('resolves a table-less TableRow target the same way as a model target', function () {
        Schema::create('pk_id_models', function ($table) {
            $table->id();
            $table->string('email');
        });

        $sanitizer = new class extends Sanitizer
        {
            public function fields(): array
            {
                return ['email' => 'safeEmail'];
            }
        };

        $resolver = app(PagingKeyResolver::class);
        $row = TableRow::forTable('pk_id_models');

        expect($row->getKeyName())->toBeNull();

        $key = $resolver->resolve($row, $sanitizer);

        expect($key->columns)->toBe(['id']);
        expect($key->source)->toBe('primary');
    });
}

namespace {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;
    use Shahirul22\LaravelPiiSanitizer\PagingKeyResolver;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class PkOptInNatural extends Model
    {
        protected $table = 'pk_opt_in_naturals';

        protected $primaryKey = 'nric';

        protected $keyType = 'string';

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;
    }

    class PkOptInKeyless extends Model
    {
        protected $table = 'pk_opt_in_keyless';

        protected $primaryKey = 'code';

        protected $keyType = 'string';

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;
    }

    class PkOptInBare extends Model
    {
        protected $table = 'pk_opt_in_bare';

        protected $primaryKey = 'nric';

        protected $keyType = 'string';

        public $incrementing = false;

        protected $guarded = [];

        public $timestamps = false;
    }

    function pkOptInSanitizer(array $fields): Sanitizer
    {
        return new class($fields) extends Sanitizer
        {
            public function __construct(private array $f) {}

            public function fields(): array
            {
                return $this->f;
            }
        };
    }

    it('pages an opted-in primary key on a disjoint unique column', function () {
        Schema::create('pk_opt_in_naturals', function ($table) {
            $table->string('nric')->primary();
            $table->integer('customer_no')->unique();
            $table->string('name')->nullable();
        });

        $key = app(PagingKeyResolver::class)->resolve(new PkOptInNatural, pkOptInSanitizer(['nric' => 'x']));

        expect($key->columns)->toBe(['customer_no']);
        expect($key->source)->toBe('unique');
    });

    it('skips the model-key branch when the model key is in fields()', function () {
        Schema::create('pk_opt_in_keyless', function ($table) {
            $table->string('code');
            $table->string('ref')->unique();
        });

        $key = app(PagingKeyResolver::class)->resolve(new PkOptInKeyless, pkOptInSanitizer(['code' => 'x']));

        expect($key->columns)->toBe(['ref']);
        expect($key->source)->toBe('unique');
    });

    it('throws optedInPrimaryKey when no disjoint identity exists', function () {
        Schema::create('pk_opt_in_bare', function ($table) {
            $table->string('nric')->primary();
            $table->string('name')->nullable();
        });

        $sanitizer = pkOptInSanitizer(['nric' => 'x']);

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PkOptInBare, $sanitizer))
            ->toThrow(UnpageableTableException::class, UnpageableTableException::optedInPrimaryKey(PkOptInBare::class, 'pk_opt_in_bare', $sanitizer::class, 'nric')->getMessage());
    });

    it('still resolves the primary key when it is not in fields()', function () {
        Schema::create('pk_opt_in_naturals', function ($table) {
            $table->string('nric')->primary();
            $table->integer('customer_no')->unique();
            $table->string('name')->nullable();
        });

        $key = app(PagingKeyResolver::class)->resolve(new PkOptInNatural, pkOptInSanitizer(['name' => 'x']));

        expect($key->columns)->toBe(['nric']);
        expect($key->source)->toBe('primary');
    });
}
