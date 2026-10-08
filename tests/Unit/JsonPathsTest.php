<?php

namespace {
    use Faker\Factory;
    use Faker\Generator;
    use Illuminate\Database\Eloquent\Model;
    use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
    use Shahirul22\LaravelPiiSanitizer\Values\Json\JsonPaths;

    class JpRow extends Model
    {
        protected $table = 'jp_rows';

        protected $guarded = [];
    }

    enum JpBacked: string
    {
        case A = 'a-val';
    }

    enum JpUnit
    {
        case Only;
    }

    class JpThrowingGenerator implements ValueGenerator
    {
        public function __invoke(mixed $value, Generator $faker, Model $row): mixed
        {
            throw InvalidReplacementValueException::unsupportedInput('Jp', 'float');
        }
    }

    function jpFaker(): Generator
    {
        static $faker = null;

        return $faker ??= Factory::create();
    }

    function jpRun(JsonPaths $paths, mixed $value, ?Model $row = null): mixed
    {
        return $paths($value, jpFaker(), $row ?? new JpRow);
    }

    /**
     * A recording closure definition: appends the leaf it received to $log and returns $return.
     *
     * @param  array<int, mixed>  $log
     */
    function jpRecorder(array &$log, mixed $return = 'X'): Closure
    {
        return function (mixed $leaf) use (&$log, $return): mixed {
            $log[] = $leaf;

            return $return;
        };
    }
}

namespace {
    use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidReplacementValueException;
    use Shahirul22\LaravelPiiSanitizer\Values\Json;
    use Shahirul22\LaravelPiiSanitizer\Values\Json\JsonPaths;

    // Construction

    it('rejects an empty map', function () {
        Json::paths([]);
    })->throws(InvalidArgumentException::class, 'at least one path');

    it('rejects a malformed path', function (string $path) {
        Json::paths([$path => 'x']);
    })->with(['', 'a->', '->a', 'a->->b', 'a[0]', 'a->b[]', 'items[12]->x'])->throws(InvalidArgumentException::class);

    it('hints at the arrow form for a bracket index', function () {
        try {
            Json::paths(['a[0]' => 'x']);
            $this->fail('expected an exception');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('->0')->toContain('->*');
        }
    });

    it('rejects overlapping paths', function (array $paths) {
        Json::paths(array_fill_keys($paths, 'x'));
    })->with([
        'prefix' => [['a', 'a->b']],
        'prefix reversed' => [['a->b', 'a']],
        'wildcard covers key' => [['a->*', 'a->b']],
        'wildcard in the middle' => [['a->b->c', 'a->*->c']],
        'root wildcard' => [['*', 'x']],
        'index and wildcard' => [['l->0', 'l->*']],
    ])->throws(InvalidArgumentException::class, 'overlap');

    it('accepts disjoint paths', function (array $paths) {
        expect(Json::paths(array_fill_keys($paths, 'x')))->toBeInstanceOf(JsonPaths::class);
    })->with([
        'siblings' => [['a->b', 'a->c']],
        'wildcard siblings' => [['a->*->x', 'a->*->y']],
        'same leaf key under different parents' => [['a->b', 'c->b']],
    ]);

    it('rejects a nested path map', function () {
        Json::paths(['a' => Json::paths(['b' => 'x'])]);
    })->throws(InvalidArgumentException::class, 'nesting');

    it('exposes definitions in declaration order with string paths', function () {
        $closure = fn () => 'x';
        $paths = Json::paths(['b->c' => 'REDACTED', 'a' => $closure, 5 => 'five']);

        $definitions = $paths->definitions();

        // PHP coerces the '5' array key to int; the path string is its (string) form.
        expect(array_map('strval', array_keys($definitions)))->toBe(['b->c', 'a', '5']);
        expect($definitions['a'])->toBe($closure);
        expect($definitions['b->c'])->toBe('REDACTED');
    });

    // String carrier

    it('rewrites a nested key and preserves the rest of the document byte-exactly (R2.2)', function () {
        $input = '{"contact":{"phone":"0123456789","email":"a@b.c"},"age":30,"vip":true,"score":1.5,"tags":["x","y"],"meta":{},"empty":[]}';
        $expected = '{"contact":{"phone":"REDACTED","email":"a@b.c"},"age":30,"vip":true,"score":1.5,"tags":["x","y"],"meta":{},"empty":[]}';

        expect(jpRun(Json::paths(['contact->phone' => 'REDACTED']), $input))->toBe($expected);
    });

    it('re-encodes with unescaped unicode and slashes and a preserved zero fraction', function () {
        $input = '{"name":"Zoë","url":"https://x.test/a","ratio":1.0,"p":"x"}';
        $expected = '{"name":"Zoë","url":"https://x.test/a","ratio":1.0,"p":"y"}';

        expect(jpRun(Json::paths(['p' => 'y']), $input))->toBe($expected);
    });

    it('addresses array indexes canonically and object members by exact key', function () {
        expect(jpRun(Json::paths(['list->1' => 'X']), '{"list":["a","b","c"]}'))->toBe('{"list":["a","X","c"]}');

        $noMatch = '{"list":["a","b","c"]}';
        expect(jpRun(Json::paths(['list->01' => 'X']), $noMatch))->toBe($noMatch);

        expect(jpRun(Json::paths(['m->01' => 'Z']), '{"m":{"1":"a","01":"b"}}'))->toBe('{"m":{"1":"a","01":"Z"}}');
    });

    it('applies a wildcard to every matched element and skips absent and null ones', function () {
        $calls = [];
        $paths = Json::paths(['emergency->*->name' => function (mixed $v) use (&$calls) {
            $calls[] = $v;

            return strtoupper($v).'!';
        }]);

        $input = '{"emergency":[{"name":"A","rel":"x"},{"name":null},{"rel":"y"},{"name":"B"}]}';
        $expected = '{"emergency":[{"name":"A!","rel":"x"},{"name":null},{"rel":"y"},{"name":"B!"}]}';

        expect(jpRun($paths, $input))->toBe($expected);
        expect($calls)->toBe(['A', 'B']);
    });

    it('matches object members in document order for a wildcard', function () {
        $calls = [];

        jpRun(Json::paths(['m->*' => jpRecorder($calls)]), '{"m":{"b":1,"a":2}}');

        expect($calls)->toBe([1, 2]);
    });

    it('matches nothing for a wildcard over an empty array or object', function () {
        $calls = [];
        $input = '{"l":[],"o":{}}';

        expect(jpRun(Json::paths(['l->*' => jpRecorder($calls), 'o->*' => jpRecorder($calls)]), $input))->toBe($input);
        expect($calls)->toBe([]);
    });

    it('skips a path by the single absent/null rule without calling the definition', function (mixed $input, string $path) {
        $calls = [];

        expect(jpRun(Json::paths([$path => jpRecorder($calls)]), $input))->toBe($input);
        expect($calls)->toBe([]);
    })->with([
        'null column' => [null, 'a'],
        'empty string' => ['', 'a'],
        'empty object' => ['{}', 'a'],
        'null on the way' => ['{"contact":null}', 'contact->phone'],
        'scalar on the way' => ['{"contact":"str"}', 'contact->phone'],
        'null leaf' => ['{"contact":{"phone":null}}', 'contact->phone'],
        'index out of range' => ['{"list":[1]}', 'list->5'],
        'scalar document' => ['42', 'a'],
        'null document' => ['null', 'a'],
        'list document' => ['[]', '0'],
        'whitespace kept' => ['{ "a" : 1 }', 'b'],
    ]);

    it('still applies the other declared paths when one is skipped, and creates no key', function () {
        $paths = Json::paths(['a' => 'R', 'b' => 'R']);

        expect(jpRun($paths, '{"a":null,"b":"x"}'))->toBe('{"a":null,"b":"R"}');
    });

    it('allows writing null over a non-null value', function () {
        expect(jpRun(Json::paths(['p' => null]), '{"p":"x"}'))->toBe('{"p":null}');
    });

    it('writes enums, arrays and objects as replacement values', function () {
        expect(jpRun(Json::paths(['p' => fn () => JpBacked::A]), '{"p":"x"}'))->toBe('{"p":"a-val"}');
        expect(jpRun(Json::paths(['p' => fn () => JpUnit::Only]), '{"p":"x"}'))->toBe('{"p":"Only"}');
        expect(jpRun(Json::paths(['p' => fn () => ['x' => 1]]), '{"p":"x"}'))->toBe('{"p":{"x":1}}');
        expect(jpRun(Json::paths(['p' => fn () => [1, 2]]), '{"p":"x"}'))->toBe('{"p":[1,2]}');
    });

    it('throws a value-free error for a malformed document', function () {
        try {
            jpRun(Json::paths(['a' => 'x']), '{"a":');
            $this->fail('expected an exception');
        } catch (InvalidReplacementValueException $e) {
            expect($e->getMessage())->toBe('[laravel-pii-sanitizer] The column value is not a valid JSON document, so its declared paths cannot be rewritten.');
        }
    });

    it('names a NUL-byte object key precisely instead of calling the document invalid (BUG-15)', function () {
        try {
            jpRun(Json::paths(['email' => 'x']), '{"\\u0000a":"secret","email":"p@q.r"}');
            $this->fail('expected an exception');
        } catch (InvalidReplacementValueException $e) {
            expect($e->getMessage())->toBe(InvalidReplacementValueException::nulByteJsonKey()->getMessage());
            expect($e->getMessage())->not->toContain('not a valid JSON document');
            expect($e->getMessage())->not->toContain('secret');
        }
    });

    // Errors and resolution

    it('rejects an unsupported carrier', function () {
        try {
            jpRun(Json::paths(['a' => 'x']), 5);
            $this->fail('expected an exception');
        } catch (InvalidReplacementValueException $e) {
            expect($e->getMessage())->toBe('[laravel-pii-sanitizer] Json::paths() received a int column value; expected a JSON string or an array.');
        }

        expect(fn () => jpRun(Json::paths(['a' => 'x']), new stdClass))->toThrow(InvalidReplacementValueException::class);
    });

    it('rejects an unwritable replacement value and names the path', function () {
        try {
            jpRun(Json::paths(['a->b' => fn () => new DateTime]), '{"a":{"b":"x"}}');
            $this->fail('expected an exception');
        } catch (InvalidReplacementValueException $e) {
            expect($e->getMessage())->toBe('[laravel-pii-sanitizer] path a->b: The value definition resolved to a DateTime, which cannot be written into JSON. Return null, a scalar, an array or an enum.');
        }

        expect(fn () => jpRun(Json::paths(['a' => fn () => INF]), '{"a":"x"}'))->toThrow(InvalidReplacementValueException::class, 'resolved to a float');
        expect(fn () => jpRun(Json::paths(['a' => fn () => [new DateTime]]), '{"a":"x"}'))->toThrow(InvalidReplacementValueException::class);
    });

    it('wraps a definition error with the path and chains the original', function () {
        try {
            jpRun(Json::paths(['a->b' => new JpThrowingGenerator]), '{"a":{"b":"x"}}');
            $this->fail('expected an exception');
        } catch (InvalidReplacementValueException $e) {
            expect($e->getMessage())->toBe('[laravel-pii-sanitizer] path a->b: The Jp value-definition cannot use an input of type float. Supported inputs are null, int, string, bool, a backed enum, or a Stringable.');
            expect($e->getPrevious())->toBeInstanceOf(InvalidReplacementValueException::class);
        }
    });

    it('resolves inner definitions through the value resolver with the leaf, faker and row', function () {
        $seen = [];
        $row = new JpRow;

        $paths = Json::paths(['a' => function (mixed $leaf, mixed $faker, mixed $r) use (&$seen) {
            $seen = [$leaf, $faker, $r];

            return 'done';
        }]);

        jpRun($paths, '{"a":"0123"}', $row);

        expect($seen[0])->toBe('0123');
        expect($seen[1])->toBe(jpFaker());
        expect($seen[2])->toBe($row);

        expect(jpRun(Json::paths(['a' => 'REDACTED']), '{"a":"0123"}'))->toBe('{"a":"REDACTED"}');
    });

    it('applies paths in declaration order regardless of document order', function () {
        $log = [];
        $paths = Json::paths([
            'b' => function () use (&$log) {
                $log[] = 'b';

                return 1;
            },
            'a' => function () use (&$log) {
                $log[] = 'a';

                return 2;
            },
        ]);

        jpRun($paths, '{"a":"x","b":"y"}');

        expect($log)->toBe(['b', 'a']);
    });

    // Array carrier

    it('rewrites the array carrier and returns an array', function () {
        $input = ['contact' => ['phone' => '0123', 'email' => 'e'], 'n' => 1, 'l' => ['a', 'b']];

        $output = jpRun(Json::paths(['contact->phone' => 'P', 'l->1' => 'Q']), $input);

        expect($output)->toBe(['contact' => ['phone' => 'P', 'email' => 'e'], 'n' => 1, 'l' => ['a', 'Q']]);
    });

    it('returns the array carrier unchanged when nothing was rewritten', function () {
        $input = ['contact' => null];

        expect(jpRun(Json::paths(['contact->phone' => 'P']), $input))->toBe($input);
        expect(jpRun(Json::paths(['contact->phone' => 'P']), []))->toBe([]);
    });

    // Static-value writability (the boot-time twin of the run-time rule)

    dataset('jp static writability', [
        'null' => [null, null],
        'bool' => [true, null],
        'int' => [1, null],
        'string' => ['x', null],
        'finite float' => [1.5, null],
        'backed enum' => [JpBacked::A, null],
        'unit enum' => [JpUnit::Only, null],
        'nested array' => [['a' => [1, 'x', null, JpBacked::A]], null],
        'INF' => [INF, 'float'],
        'NAN' => [NAN, 'float'],
        'object' => [new stdClass, 'stdClass'],
        'nested object' => [['ok' => 1, 'bad' => ['deep' => new ArrayObject]], 'ArrayObject'],
        'float in list' => [[1, -INF], 'float'],
    ]);

    it('reports the first non-JSON-writable type of a static value, or null', function (mixed $value, ?string $expected) {
        expect(JsonPaths::unwritableType($value))->toBe($expected);
    })->with('jp static writability');

    it('agrees with the run-time writability rule', function (mixed $value, ?string $expected) {
        $run = fn () => jpRun(Json::paths(['p' => fn () => $value]), '{"p":"x"}');

        if ($expected !== null) {
            expect($run)->toThrow(InvalidReplacementValueException::class);
        } else {
            expect($run)->not->toThrow(InvalidReplacementValueException::class);
        }
    })->with('jp static writability');
}
