<?php

use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\SanitizerResolverContract;
use Shahirul22\LaravelPiiSanitizer\Sanitizer;

class FakeSanitizer extends Sanitizer
{
    public function fields(): array
    {
        return [
            'email' => 'email',
            'name' => fn () => 'x',
        ];
    }
}

class FakeUser extends Model
{
    protected $table = 'fake_users';
}

class FakeSanitizerResolver implements SanitizerResolverContract
{
    public mixed $received = null;

    public function __construct(public ?Sanitizer $return = null) {}

    public function resolve(Model|string $model): ?Sanitizer
    {
        $this->received = $model;

        return $this->return;
    }
}

it('is abstract and cannot be instantiated directly', function () {
    expect((new ReflectionClass(Sanitizer::class))->isAbstract())->toBeTrue();
});

it('declares an abstract fields(): array contract', function () {
    $reflection = new ReflectionClass(Sanitizer::class);

    expect($reflection->hasMethod('fields'))->toBeTrue();

    $method = $reflection->getMethod('fields');

    expect($method->isAbstract())->toBeTrue();
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('lets a concrete subclass return a column => value-definition map', function () {
    $sanitizer = new FakeSanitizer;

    $fields = $sanitizer->fields();

    expect($fields)->toBeArray();
    expect(array_keys($fields))->toBe(['email', 'name']);
    expect($fields['email'])->toBe('email');
    expect($fields['name'])->toBeInstanceOf(Closure::class);
});

it('Sanitizer::for() delegates to the container-bound SanitizerResolver and returns its result', function () {
    $expected = new FakeSanitizer;
    $fake = new FakeSanitizerResolver($expected);
    app()->instance(SanitizerResolverContract::class, $fake);

    $model = new FakeUser;

    expect(Sanitizer::for($model))->toBe($expected);
});

it('Sanitizer::for() returns null when the resolver resolves nothing', function () {
    $fake = new FakeSanitizerResolver(null);
    app()->instance(SanitizerResolverContract::class, $fake);

    $model = new FakeUser;

    expect(Sanitizer::for($model))->toBeNull();
});

it('Sanitizer::for() accepts both a model instance and a model class-string', function () {
    $fake = new FakeSanitizerResolver(new FakeSanitizer);
    app()->instance(SanitizerResolverContract::class, $fake);

    $model = new FakeUser;
    Sanitizer::for($model);
    expect($fake->received)->toBe($model);

    Sanitizer::for(FakeUser::class);
    expect($fake->received)->toBe(FakeUser::class);
});
