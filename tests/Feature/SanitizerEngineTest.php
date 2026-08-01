<?php

namespace {
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Sanitizer;

    class EngineUser extends Model
    {
        protected $table = 'engine_users';

        protected $guarded = [];

        public $timestamps = false;
    }

    class EngineRedactGenerator implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            return '[redacted]';
        }
    }

    class EngineUserSanitizer extends Sanitizer
    {
        public function fields(): array
        {
            return [
                'status' => 'inactive',
                'email' => 'safeEmail',
                'name' => fn ($value, $faker, $row) => 'was:'.$value,
                'note' => EngineRedactGenerator::class,
            ];
        }
    }
}

namespace {

    use Faker\Generator;
    use Illuminate\Support\Facades\Schema;
    use Shahirul22\LaravelPiiSanitizer\ValueDefinitionResolver;

    it('resolves all four value-definition types end-to-end through Testbench', function () {
        Schema::create('engine_users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->string('note')->nullable();
        });

        $row = EngineUser::create([
            'name' => 'Alice',
            'email' => 'alice@real.com',
            'status' => 'active',
            'note' => 'secret',
        ]);

        // Instantiated directly rather than via Sanitizer::for(), since sanitizer
        // resolution by convention/config is already covered by SanitizerResolverTest;
        // this test focuses purely on value-definition resolution end-to-end.
        $sanitizer = new EngineUserSanitizer;

        $resolver = app(ValueDefinitionResolver::class);
        $faker = app(Generator::class);

        $resolved = [];

        foreach ($sanitizer->fields() as $column => $definition) {
            $resolved[$column] = $resolver->resolve($definition, $row->{$column}, $faker, $row);
        }

        expect($resolved['status'])->toBe('inactive');
        expect($resolved['email'])->toBeString();
        expect(str_contains($resolved['email'], '@'))->toBeTrue();
        expect($resolved['email'])->not->toBe('alice@real.com');
        expect($resolved['name'])->toBe('was:Alice');
        expect($resolved['note'])->toBe('[redacted]');

        $row->update($resolved);
        $row->refresh();

        expect($row->status)->toBe('inactive');
        expect($row->name)->toBe('was:Alice');
        expect($row->note)->toBe('[redacted]');
    });
}
