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

namespace {
    use Illuminate\Database\Connection;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraintInspector;
    use Shahirul22\LaravelPiiSanitizer\ColumnConstraints;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\UnpageableTableException;
    use Shahirul22\LaravelPiiSanitizer\PagingKeyResolver;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;
    use Shahirul22\LaravelPiiSanitizer\UniqueColumnInspector;

    /** Default model key `id`, on tables that have no primary key. */
    class PkProofModel extends Model
    {
        protected $table = 'pk_proof_rows';

        protected $guarded = [];

        public $timestamps = false;
    }

    /**
     * @param  list<string>  $pagingKey
     */
    function pkProofSanitizer(array $fields, array $pagingKey = []): Sanitizer
    {
        return new class($fields, $pagingKey) extends Sanitizer
        {
            public function __construct(private array $f, private array $p) {}

            public function fields(): array
            {
                return $this->f;
            }

            public function pagingKey(): array
            {
                return $this->p;
            }
        };
    }

    it('does not trust a non-unique model-key column on a keyless table and falls through (BUG-3)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->integer('id');
            $table->integer('seq');
            $table->string('email');
        });

        foreach ([1, 1, 1, 2, 2, 3] as $i => $id) {
            DB::table('pk_proof_rows')->insert(['id' => $id, 'seq' => $i, 'email' => "e{$i}@x.test"]);
        }

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['id', 'seq']);
    });

    it('does not trust a nullable model-key column on a keyless table (BUG-3)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->integer('id')->nullable();
            $table->integer('seq');
            $table->string('email');
        });

        foreach ([1, 2, 3] as $i => $id) {
            DB::table('pk_proof_rows')->insert(['id' => $id, 'seq' => $i, 'email' => "e{$i}@x.test"]);
        }

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['seq']);
    });

    it('still uses a proven-unique NOT NULL model-key column on a keyless table (BUG-3)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->integer('id');
            $table->string('email');
        });

        foreach ([3, 1, 2] as $i => $id) {
            DB::table('pk_proof_rows')->insert(['id' => $id, 'email' => "e{$i}@x.test"]);
        }

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('model-key');
        expect($key->columns)->toBe(['id']);
    });

    it('skips a unique index on a nullable column (BUG-28)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('code')->nullable()->unique();
            $table->string('ref');
            $table->string('email');
        });

        // One NULL only: the uniqueness probe groups NULLs together, so two
        // of them would hide a missing NOT NULL check.
        DB::table('pk_proof_rows')->insert(['code' => null, 'ref' => 'r1', 'email' => 'a@x.test']);
        DB::table('pk_proof_rows')->insert(['code' => 'b', 'ref' => 'r2', 'email' => 'b@x.test']);
        DB::table('pk_proof_rows')->insert(['code' => 'c', 'ref' => 'r3', 'email' => 'c@x.test']);

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['ref']);
    });

    it('rejects a declared pagingKey() whose values are not unique and falls through (BUG-28)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('ref');
            $table->string('code');
            $table->string('email');
        });

        DB::table('pk_proof_rows')->insert(['ref' => 'dup', 'code' => 'c1', 'email' => 'a@x.test']);
        DB::table('pk_proof_rows')->insert(['ref' => 'dup', 'code' => 'c2', 'email' => 'b@x.test']);

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail'], ['ref']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['ref', 'code']);
    });

    it('rejects a declared pagingKey() on a nullable column and falls through (BUG-28)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('ref')->nullable();
            $table->string('code');
            $table->string('email');
        });

        DB::table('pk_proof_rows')->insert(['ref' => 'r1', 'code' => 'c1', 'email' => 'a@x.test']);
        DB::table('pk_proof_rows')->insert(['ref' => 'r2', 'code' => 'c2', 'email' => 'b@x.test']);

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail'], ['ref']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['code']);
    });

    it('leaves json and binary columns out of the keyless fallback (BUG-28)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('first');
            $table->json('payload');
            $table->binary('blob');
            $table->string('email');
        });

        DB::table('pk_proof_rows')->insert(['first' => 'a', 'payload' => '{"k":1}', 'blob' => 'x', 'email' => 'a@x.test']);
        DB::table('pk_proof_rows')->insert(['first' => 'b', 'payload' => '{"k":2}', 'blob' => 'y', 'email' => 'b@x.test']);

        // SQLite stores a json column as text, so report it the way MySQL
        // and PostgreSQL do. The binary column is a real SQLite blob.
        app()->instance(ColumnConstraintInspector::class, new class(app('db')) extends ColumnConstraintInspector
        {
            public function constraintsFor(string $table, ?string $connection = null): array
            {
                $constraints = parent::constraintsFor($table, $connection);
                $constraints['payload'] = new ColumnConstraints('payload', 'json', null, null, false);

                return $constraints;
            }
        });

        expect(app(ColumnConstraintInspector::class)->constraintsFor('pk_proof_rows')['blob']->family)->toBe('binary');

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['first']);
    });

    it('leaves columns of an unknown type family out of the keyless fallback (BUG-45)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('first');
            $table->integer('n');
            $table->boolean('flag');
            $table->dateTime('at');
            $table->geometry('loc');
            $table->string('email');
        });

        DB::table('pk_proof_rows')->insert(['first' => 'a', 'n' => 1, 'flag' => true, 'at' => '2024-01-01 00:00:00', 'loc' => 'p1', 'email' => 'a@x.test']);

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['first', 'n', 'flag', 'at']);
    });

    it('treats a failing uniqueness probe as not proven instead of crashing (BUG-45)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('ref');
            $table->string('email');
        });

        DB::table('pk_proof_rows')->insert(['ref' => 'r1', 'email' => 'a@x.test']);

        // Stand-in for PostgreSQL's "could not identify an equality operator
        // for type point": the probe query itself fails.
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if (str_contains(strtolower($query), 'having count(*) > 1')) {
                throw new QueryException($connection->getName(), $query, $bindings, new PDOException('could not identify an equality operator'));
            }
        });

        expect(fn () => app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail'], ['ref'])))
            ->toThrow(UnpageableTableException::class, 'pk_proof_rows');
    });

    it('does not trust a partial unique index whose column repeats outside the predicate (BUG-39)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('code');
            $table->boolean('deleted');
            $table->string('ref');
            $table->string('email');
        });

        DB::statement('create unique index pk_proof_rows_live_code on pk_proof_rows (code) where deleted = 0');

        DB::table('pk_proof_rows')->insert(['code' => 'a', 'deleted' => true, 'ref' => 'r1', 'email' => 'a1@x.test']);
        DB::table('pk_proof_rows')->insert(['code' => 'a', 'deleted' => true, 'ref' => 'r2', 'email' => 'a2@x.test']);
        DB::table('pk_proof_rows')->insert(['code' => 'b', 'deleted' => false, 'ref' => 'r3', 'email' => 'b@x.test']);

        expect(app(UniqueColumnInspector::class)->uniqueConstraints('pk_proof_rows'))->toContain(['code']);

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail']));

        expect($key->source)->toBe('fallback');
        expect($key->columns)->toBe(['code', 'deleted', 'ref']);
    });

    it('skips a unique index made only of expressions instead of failing at boot (BUG-40)', function () {
        Schema::create('pk_proof_rows', function ($table) {
            $table->string('ref');
            $table->string('email');
        });

        DB::statement('create unique index pk_proof_rows_lower_email on pk_proof_rows (lower(email))');

        DB::table('pk_proof_rows')->insert(['ref' => 'r1', 'email' => 'a@x.test']);
        DB::table('pk_proof_rows')->insert(['ref' => 'r2', 'email' => 'b@x.test']);

        // The schema builder reports the expression-only index with no columns.
        $expressionIndex = collect(Schema::getIndexes('pk_proof_rows'))->firstWhere('name', 'pk_proof_rows_lower_email');
        expect($expressionIndex['columns'])->toBe([]);
        expect($expressionIndex['unique'])->toBeTrue();

        expect(app(UniqueColumnInspector::class)->uniqueConstraints('pk_proof_rows'))->not->toContain([]);

        $key = app(PagingKeyResolver::class)->resolve(new PkProofModel, pkProofSanitizer(['email' => 'safeEmail'], ['ref']));

        expect($key->source)->toBe('declared');
        expect($key->columns)->toBe(['ref']);
    });
}
