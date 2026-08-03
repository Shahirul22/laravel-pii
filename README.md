# Laravel PII Sanitizer

`shahirul22/laravel-pii-sanitizer` sanitizes PII columns in your database during local and development workflows — for example, right after importing a production dump into your local environment. You declare, per Eloquent model, a class-based `Sanitizer` describing which columns hold PII and how to replace them, then run a single Artisan command.

## Safety guarantees

> **This is a development-time tool. Never point it at a production database.** It rewrites rows in place and there is no undo.

Three guarantees, stated before anything else:

### 1. The environment guard refuses to run outside your allow-list

The command refuses to run when the application environment is not listed in `config('pii.environments')` (default `['local', 'testing']`). The refusal is hard: it exits with code `1` and writes nothing.

To override, you must pass `--force` **and** answer an interactive confirmation prompt:

```bash
php artisan pii:sanitize --force
```

The prompt defaults to **no**. Under `--no-interaction`, or anywhere without a TTY (CI, a scheduler, a deploy hook), the confirmation resolves to `false` — so `--force` on its own is never enough to sanitize outside your allow-list.

### 2. `--dry-run` writes nothing

```bash
php artisan pii:sanitize --dry-run
```

Reports the number of rows that would be sanitized per model, plus a per-column breakdown of how many rows each column would change. It opens no transaction and issues no write. Use it first, every time.

### 3. Unsafe columns are rejected before a single row is read

Foreign-key columns and columns referenced by another table's foreign key are rejected at sanitizer-resolution time — before the engine reads any row — rather than being rewritten. Rewriting a referenced key would break relational integrity, so v1 refuses instead.

## Requirements

- PHP `^8.2`
- Laravel (`illuminate/*`) `^11.0 || ^12.0 || ^13.0`

v1 operates on your **single default database connection** only.

## Installation

Install as a dev dependency — this package has no place in a production build:

```bash
composer require --dev shahirul22/laravel-pii-sanitizer
```

`Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider` is registered through Laravel's package auto-discovery, so **no manual provider registration is required**.

Publish the config file:

```bash
php artisan vendor:publish --tag=pii-config
```

This writes `config/pii.php`. Publishing is optional in principle — the package merges its own defaults — but you will need the published file in practice, because `pii.models` defaults to an empty list and nothing is sanitized until you populate it.

(Equivalent, if you prefer targeting the provider: `php artisan vendor:publish --provider="Shahirul22\LaravelPiiSanitizer\PiiSanitizerServiceProvider"`.)

## Configuration

`config/pii.php`:

| Key | Default | Meaning |
|---|---|---|
| `models` | `[]` | The explicit, ordered list of model classes the engine walks. There is no filesystem auto-discovery in v1 — an empty list yields an empty run report, not an error. |
| `sanitizers` | `[]` | Explicit `model-FQCN => sanitizer-FQCN` overrides. An entry here always beats the `App\Sanitizers\{Model}Sanitizer` convention. |
| `protected_columns` | `['created_at', 'updated_at', 'deleted_at']` | Audit/temporal columns never written unless you explicitly declare them in a `fields()` map. Merged with each model's own timestamp columns. |
| `environments` | `['local', 'testing']` | The environment guard's allow-list. |
| `chunk` | `['size' => env('PII_CHUNK_SIZE'), 'min' => 500, 'max' => 5000, 'target_chunks' => 20]` | `size` is `null` by default, meaning chunks are sized automatically; `min`, `max`, and `target_chunks` tune the automatic algorithm. |

Set `PII_CHUNK_SIZE` in your `.env` to force a fixed chunk size. `env()` is called only inside `config/pii.php`, so `php artisan config:cache` resolves correctly.

### Overriding sanitizer resolution

A model's sanitizer is normally found by convention (see below). When it is not — a model outside `App\Models`, a third-party model, or a sanitizer you want to swap — map it explicitly:

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

`fields()` maps a column name to a value definition. Here both values are Faker method names — the simplest of the four supported forms. `safeEmail` is used rather than `email` so the generated addresses land in reserved example domains and can never reach a real inbox.

### Resolution is by convention

Because the class is `App\Sanitizers\UserSanitizer`, it is discovered automatically for `App\Models\User` via the `App\Sanitizers\{Model}Sanitizer` convention. **No `pii.sanitizers` entry is needed.**

Resolution order:

1. An explicit `config('pii.sanitizers')` entry for the model — always wins.
2. The conventional `App\Sanitizers\{Model}Sanitizer` class, if it exists.
3. Otherwise the model is skipped, and reported as `skipped — no sanitizer`.

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

A progress bar is rendered per model while chunks are processed (one line per model, redrawn in place); the exact number of chunks depends on row count and the configured/automatic chunk size.

## Value definition forms

A `fields()` value may be any of four things:

**1. A static value** — any scalar, array, enum, or `null`:

```php
'phone' => null,
'country' => 'MY',
```

**2. A closure** receiving the current value, a Faker generator, and the model row:

```php
'email' => fn (mixed $value, \Faker\Generator $faker, \Illuminate\Database\Eloquent\Model $row): mixed
    => $faker->userName().'@example.test',
```

**3. A class-string implementing `ValueGenerator`** — for logic reused across fields or models:

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

**4. A Faker method name** — the form the example above uses:

```php
'name' => 'name',
'email' => 'safeEmail',
```

### Preserving a column's value distribution

Override `categorical()` to list columns whose replacement values should preserve the column's original distribution. Every column named there must also appear in `fields()`:

```php
public function categorical(): array
{
    return ['status'];
}
```

## Limitations (v1)

- **Flat columns only.** Foreign-key columns, and columns referenced by another table's foreign key, are rejected at sanitizer-resolution time — before any row is read. Rewriting a referenced key risks breaking relational integrity, so v1 refuses rather than guessing.
- **Single default connection.** The engine operates on your default database connection only.
- **No model auto-discovery.** `pii.models` is an explicit, ordered list; nothing is discovered by scanning the filesystem.
- **Development-time only.** There is no production story, by design.

## License

MIT.
