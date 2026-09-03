# Laravel PII Sanitizer

`shahirul22/laravel-pii-sanitizer` sanitizes PII columns in your database during local and development workflows, for example right after importing a production dump into your local environment. You declare, per Eloquent model, a class-based `Sanitizer` describing which columns hold PII and how to replace them, then run a single Artisan command.

## Safety guarantees

> **This is a development-time tool. Never point it at a production database.** It rewrites rows in place and there is no undo.

### The environment guard refuses to run outside your allow-list

The command refuses to run when the application environment is not listed in `config('pii.environments')` (default `['local', 'testing']`). The refusal is hard: it exits with code `1` and writes nothing.

To override, you must pass `--force` and answer an interactive confirmation prompt:

```bash
php artisan pii:sanitize --force
```

The prompt defaults to no. Under `--no-interaction`, or anywhere without a TTY (CI, a scheduler, a deploy hook), the confirmation resolves to `false`, so `--force` on its own is never enough to sanitize outside your allow-list.

### `--dry-run` writes nothing

```bash
php artisan pii:sanitize --dry-run
```

Reports the number of rows that would be sanitized per model, plus a per-column breakdown of how many rows each column would change. It opens no transaction and issues no write. Use it first, every time.

### Unsafe columns are rejected before a single row is read

Foreign-key columns, columns referenced by another table's foreign key, and a table's own primary key are rejected at sanitizer-resolution time, before the engine reads any row, rather than being rewritten. Rewriting a referenced key or the primary key itself would break relational integrity (or the chunked read that pages by it), so the package refuses instead.

### Timestamp columns are never touched unless you declare them

The engine only ever writes the columns you declare in `fields()`. A model's `created_at`/`updated_at` (and `deleted_at` under `SoftDeletes`) are left exactly as they were unless you explicitly add them to `fields()` yourself — there is no separate opt-out needed.

## Requirements

- PHP `^8.2`
- Laravel (`illuminate/*`) `^11.0 || ^12.0 || ^13.0`

The package operates on your single default database connection only.

## Installation

Install as a dev dependency. This package has no place in a production build:

```bash
composer require --dev shahirul22/laravel-pii-sanitizer
```

`Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider` is registered through Laravel's package auto-discovery, so you don't need to register the provider manually.

Publish the config file:

```bash
php artisan vendor:publish --tag=pii-config
```

This writes `config/pii.php`. Publishing is optional in principle, since the package merges its own defaults, but you'll need the published file in practice: `pii.models` defaults to an empty list and nothing is sanitized until you populate it.

(Equivalent, if you prefer targeting the provider: `php artisan vendor:publish --provider="Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider"`.)

## Configuration

`config/pii.php`:

| Key | Default | Meaning |
|---|---|---|
| `models` | `[]` | The explicit, ordered list of model classes the engine walks. There is no filesystem auto-discovery, so an empty list yields an empty run report rather than an error. |
| `sanitizers` | `[]` | Explicit `model-FQCN => sanitizer-FQCN` overrides. An entry here always beats the `App\Sanitizers\{Model}Sanitizer` convention. |
| `environments` | `['local', 'testing']` | The environment guard's allow-list. |
| `chunk` | `['size' => env('PII_CHUNK_SIZE'), 'min' => 500, 'max' => 5000, 'target_chunks' => 20]` | `size` is `null` by default, meaning chunks are sized automatically. `min`, `max`, and `target_chunks` tune the automatic algorithm. |

Set `PII_CHUNK_SIZE` in your `.env` to force a fixed chunk size. `env()` is called only inside `config/pii.php`, so `php artisan config:cache` resolves correctly.

### Overriding sanitizer resolution

A model's sanitizer is normally found by convention (see below). When it isn't, for a model outside `App\Models`, a third-party model, or a sanitizer you want to swap, map it explicitly:

```php
'sanitizers' => [
    \App\Models\User::class => \App\Sanitizers\CustomUserSanitizer::class,
],
```

An explicit entry always wins over the convention.

## Defining a Sanitizer

Sanitizers are standalone classes, in the spirit of Laravel's model factories. Create `app/Sanitizers/UserSanitizer.php`:

```php
<?php

namespace App\Sanitizers;

use Shahirul22\LaravelPiiSanitizer\Sanitizer;

class UserSanitizer extends Sanitizer
{
    public function fields(): array
    {
        return [
            'name' => 'name',
            'email' => 'safeEmail',
        ];
    }
}
```

`fields()` maps a column name to a value definition. Here both values are Faker method names, the simplest of the four supported forms. `safeEmail` is used instead of `email` so the generated addresses land in reserved example domains and can never reach a real inbox.

### Resolution is by convention

Because the class is `App\Sanitizers\UserSanitizer`, it's discovered automatically for `App\Models\User` via the `App\Sanitizers\{Model}Sanitizer` convention. No `pii.sanitizers` entry is needed.

Resolution order:

1. An explicit `config('pii.sanitizers')` entry for the model, which always wins.
2. The conventional `App\Sanitizers\{Model}Sanitizer` class, if it exists.
3. Otherwise the model is skipped and reported as `skipped — no sanitizer`.

### Register the model

`pii.models` defaults to `[]` and there is no filesystem auto-discovery, so the model must be listed explicitly in `config/pii.php`:

```php
'models' => [
    \App\Models\User::class,
],
```

Without this entry the run walks zero models and reports nothing.

You can also resolve a model's sanitizer programmatically:

```php
use Shahirul22\LaravelPiiSanitizer\Sanitizer;

$sanitizer = Sanitizer::for(\App\Models\User::class); // ?Sanitizer
```

## Running the command

```bash
php artisan pii:sanitize --dry-run
```

Always dry-run first. Once the report looks right, drop the flag to write:

```bash
php artisan pii:sanitize
```

### Options

| Option | Effect |
|---|---|
| `--dry-run` | Report what would change without writing anything to the database |
| `--force` | Allow the run to proceed outside the allowed environments (an interactive confirmation is still required) |
| `--chunk=` | Rows per chunk; overrides config `pii.chunk.size` and automatic sizing. Must be a positive integer. |
| `--model=*` | Fully-qualified model class to sanitize; repeatable, defaults to config `pii.models` |

`pii.models` is the *default* target list used when `--model` is omitted, not an allow-list `--model` is restricted to. Passing `--model` is an intentional override: it can target any model, including one deliberately left out of `pii.models` — useful for a one-off run against a single model before adding it to config. It is still fully subject to the same schema-safety checks (FK/FK-referenced/primary-key column rejection) as a `pii.models`-driven run.

Exit code `0` on a successful run or a completed dry run; `1` on an environment-guard refusal, an invalid `--chunk` value, or a failed run.

### Dry-run output

For the example above, against a database with 25 users:

```
 INFO  Dry run — no data will be written.

 INFO  Sanitizing 1 model(s).

App\Models\User: 0/1 chunks [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
App\Models\User: 1/1 chunks [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 App\Models\User .. 25 rows would be sanitized
 Column breakdown for App\Models\User:
 name .. 25 rows would change
 email .. 25 rows would change

 WARN  Dry run complete — nothing was written to the database.
```

A progress bar is rendered per model while chunks are processed, one line per model, redrawn in place. The exact number of chunks depends on row count and the configured or automatic chunk size.

## Value definition forms

A `fields()` value can be any of four things.

A static value: any scalar, array, enum, or `null`.

```php
'phone' => null,
'country' => 'MY',
```

A closure receiving the current value, a Faker generator, and the model row:

```php
'email' => fn (mixed $value, \Faker\Generator $faker, \Illuminate\Database\Eloquent\Model $row): mixed
    => $faker->userName().'@example.test',
```

A class-string implementing `ValueGenerator`, for logic reused across fields or models:

```php
<?php

namespace App\Sanitizers;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Contracts\ValueGenerator;

class RedactedName implements ValueGenerator
{
    public function __invoke(mixed $value, Generator $faker, Model $row): mixed
    {
        return 'user-'.$row->getKey();
    }
}
```

```php
'name' => \App\Sanitizers\RedactedName::class,
```

A Faker method name, the form the example above uses:

```php
'name' => 'name',
'email' => 'safeEmail',
```

### Preserving a column's uniqueness

Any column carrying a unique (or composite-unique) database constraint automatically has its generated replacement values checked against both the existing values already in that column and every value generated earlier in the same run — no opt-in declaration needed. If a value definition's space is too small to keep producing unique values, the run stops with a `UniquenessExhaustedException` naming the column and sanitizer, rather than silently writing a duplicate or looping forever. Widen the value definition (e.g. `$faker->unique()->safeEmail()`, or append the row's primary key) if you hit this.

### Preserving a column's value distribution

Override `categorical()` to list columns whose replacement values should preserve the column's original distribution. Every column named there must also appear in `fields()`:

```php
public function categorical(): array
{
    return ['status'];
}
```

Declaring a column `categorical()` changes *how* its `fields()` value is generated, not whether one is required: the engine draws a replacement by resampling from the column's own real (pre-sanitization) value distribution instead of invoking the declared static value/closure/generator/Faker call. This is the intended v1 semantic for approximating a categorical column's original distribution (a finite, repeated value set), not a bypass of `fields()` — but it does mean a categorical column's `fields()` definition governs only its shape (it must still be declared), not the actual replacement value written. Do not declare a column `categorical()` if you need its literal `fields()` value to always be the one written.

## Known limitations

- **Flat columns only.** Foreign-key columns, columns referenced by another table's foreign key, and a table's own primary key are rejected at sanitizer-resolution time, before any row is read. Rewriting a referenced key or the primary key risks breaking relational integrity (or the chunked read itself), so the package refuses rather than guessing.
- **Single default connection.** The engine operates on your default database connection only.
- **No model auto-discovery.** `pii.models` is an explicit, ordered list; nothing is discovered by scanning the filesystem.
- **Development-time only.** There is no production story, by design.

## License

MIT.
