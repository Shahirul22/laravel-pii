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

The prompt defaults to no. Under `--no-interaction` (or `-n`), the confirmation resolves to `false`, so `--force` on its own is never enough to sanitize outside your allow-list. A missing TTY alone does not switch the prompt off: if input is piped in (for example `echo yes | php artisan pii:sanitize --force`), the prompt can be answered `true`. So do not use `--force` in scripts, CI jobs or deploy hooks.

The guard checks the name of the environment (`APP_ENV`) against the allow-list. It does not check which database the application connects to. An application with `APP_ENV=local` that is pointed at a production database is allowed to run.

### `--dry-run` writes nothing

```bash
php artisan pii:sanitize --dry-run
```

Reports the number of rows that would be sanitized per model, plus a per-column breakdown of how many rows each column would change. It opens no transaction and writes no data. Use it first, every time.

### Unsafe columns are rejected before a single row is read

By default, the package rejects three kinds of column at sanitizer-resolution time, before the engine reads any row, rather than rewriting them:

- A foreign-key column.
- A column that another table's foreign key references.
- A primary-key column. For a composite primary key, this means every column of the key.

Rewriting such a column on its own would break relational integrity, or the chunked read that pages by it, so the package refuses instead. The rejection of a foreign-key column or a referenced column ends with this hint:

```text
To sanitize it consistently with the columns that hold the same value, declare it in mirrors() with a Keyed definition.
```

You can lift the rejection for one column at a time. You list the column in `mirrors()` together with every column that holds the same value, and you give all of them a `Keyed` definition in one namespace. Nothing else lifts it. See [Referenced columns](#referenced-columns).

Opting in also switches foreign-key enforcement off for the run, and on PostgreSQL it stops ordinary triggers too. Read [Foreign-key enforcement during the run](#foreign-key-enforcement-during-the-run) and [Database requirements and side effects](#database-requirements-and-side-effects) before you opt a column in.

### Timestamp columns are never touched unless you declare them

The engine only ever writes the columns you declare in `fields()`. A model's `created_at`/`updated_at` (and `deleted_at` under `SoftDeletes`) are left exactly as they were unless you explicitly add them to `fields()` yourself — there is no separate opt-out needed.

## What this package does not do

Read this before you install. It tells you what the package leaves out and what it is not. The two points below are not planned features. For the features that are planned, see the [Roadmap](#roadmap).

### It does not handle file or binary PII

The package sanitizes values in database columns. It does not handle file or binary blob PII. This includes:

- Uploaded files, such as scanned identity cards, photos, PDFs or other documents.
- `BLOB` columns and other binary columns.
- Files that a column refers to by a path or a URL. The package can rewrite the text of the path or the URL, but it never opens, changes or deletes the file the path points to.

If your database refers to files like these, the PII in them stays. Deal with it outside this package, for example by not copying the files to your development machine.

### It is not a security control

The package is a development-time tool that rewrites a copy of your data. It is not a security control:

- It is not encryption-at-rest. It does not protect data stored in a database or on a disk.
- It is not field-level encryption. A sanitized value is a replacement, not an encrypted form of the original.
- It is not dynamic data masking. It does not hide values from users who query a live database. It rewrites rows once, in place, and there is no undo.

Do not rely on it to protect live data, and do not assume that a sanitized copy is safe to publish. A [`Keyed`](#deterministic-keyed-values) value, which is the same every time for the same input, still reveals some things about the original. See [What a keyed value still reveals](#what-a-keyed-value-still-reveals).

## Requirements

- PHP `^8.2`
- Laravel (`illuminate/*`) `^11.0 || ^12.0 || ^13.0`
- The `ext-mbstring` PHP extension, because the [format-preserving helpers](#format-preserving-helpers) use the `mb_*` functions. Laravel already requires it (`illuminate/support` needs it), so a normal Laravel install has it.

Only the default database connection is supported and tested. A model that sets its own `$connection` is read and written on that connection, so do not list such models.

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
| `tables` | `[]` | An explicit `table-name => sanitizer-FQCN` map for tables that have no Eloquent model, such as a pivot table. There is no convention lookup for tables. See [Tables without a model](#tables-without-a-model). |
| `environments` | `['local', 'testing']` | The environment guard's allow-list. |
| `chunk` | `['size' => env('PII_CHUNK_SIZE'), 'min' => 500, 'max' => 5000, 'target_chunks' => 20]` | `size` is `null` by default, meaning chunks are sized automatically. `min`, `max`, and `target_chunks` tune the automatic algorithm. |
| `keyed` | `['key' => env('PII_SANITIZER_KEY')]` | The secret key behind [`Keyed`](#deterministic-keyed-values) values. It is only needed when a sanitizer declares a `Keyed` field. See [Setting the key](#setting-the-key). |

Set `PII_CHUNK_SIZE` in your `.env` to force a fixed chunk size. `env()` is called only inside `config/pii.php`, so `php artisan config:cache` resolves correctly.

`PII_SANITIZER_KEY` is read only inside `config/pii.php`, like `PII_CHUNK_SIZE`.

If you published `config/pii.php` before the `tables` and `keyed` keys existed, it keeps working. The package merges its own defaults for any top-level key that your file does not have. Add the keys to your file only when you want to change them.

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

`fields()` maps a column name to a value definition. Here both values are Faker method names, the simplest of the supported forms (see [Value definition forms](#value-definition-forms)). `safeEmail` is used instead of `email` so the generated addresses land in reserved example domains and can never reach a real inbox.

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

`pii.models` is the *default* target list used when `--model` is omitted, not an allow-list `--model` is restricted to. Passing `--model` is an intentional override: it can target any model, including one deliberately left out of `pii.models` — useful for a one-off run against a single model before adding it to config. It is still fully subject to the same boot-time checks as a `pii.models`-driven run, before any row is read.

`--model` targets models only. It skips every `pii.tables` entry, so a table target never runs in a `--model` run. See [Tables without a model](#tables-without-a-model).

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

A `fields()` value can be any of five things.

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

A `ValueGenerator` instance. The package ships its own: [`Keyed::…`](#deterministic-keyed-values), [`Format::…`](#format-preserving-helpers), [`Malaysia::…`](#malaysia-generators) and `Json::paths(…)` (see [JSON columns](#json-columns)). You call a static factory and put the object it returns in `fields()`:

```php
'phone' => Format::keepLast(4),
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

This check has a cost in memory and in reads. For each unique constraint that involves a declared column, the package reads the existing values of its columns once per run, as a `DISTINCT` scan of the whole table. It keeps those values, and every value it generates, in memory until the run ends. This holds for a random definition and for a `Keyed` one. A `Keyed` definition on a unique column keeps more on top of that (see [Namespaces and patterns](#namespaces-and-patterns)). Plan for this on very large tables.

A random value definition gets up to 100 attempts per value before the run stops with that exception. A [`Keyed`](#deterministic-keyed-values) definition on a unique column does not use this retry loop. It is resolved deterministically: if a replacement collides with another value, the package tries the next candidate for the same input, up to 100 probes. If all 100 collide, the run stops with a `UniquenessExhaustedException` and this message (the namespace `staff` is an example):

```text
[laravel-pii-sanitizer] Keyed namespace "staff" could not find a unique replacement after 100 probes. The shape's value space is too small for the unique column(s) it is bound to — widen it (a longer pattern or a larger shape).
```

A masked helper, `Format::keepLength('*')`, always gives the same output for values of the same shape, that is, the same length with the same separators in the same places. On a unique column it runs out of attempts as soon as two values have the same shape. Use `Format::keepLength()` without a mask, or a `Keyed` form, on unique columns. See [Format-preserving helpers](#format-preserving-helpers).

### Preserving a column's value distribution

Override `categorical()` to list columns whose replacement values should preserve the column's original distribution. Every column named there must also appear in `fields()`:

```php
public function categorical(): array
{
    return ['status'];
}
```

Declaring a column `categorical()` changes *how* its `fields()` value is generated, not whether one is required: the engine draws a replacement by resampling from the column's own real (pre-sanitization) value distribution instead of invoking the declared static value/closure/generator/Faker call. This is the intended v1 semantic for approximating a categorical column's original distribution (a finite, repeated value set), not a bypass of `fields()` — but it does mean a categorical column's `fields()` definition governs only its shape (it must still be declared), not the actual replacement value written. Do not declare a column `categorical()` if you need its literal `fields()` value to always be the one written.

A column that uses a [`Keyed`](#deterministic-keyed-values) definition cannot be `categorical()`. The two do opposite things: a categorical column is sampled from its own distribution, which would override the keyed value. The package rejects this at boot, before it reads any row. The message looks like this (the model, column and sanitizer names are examples):

```text
[laravel-pii-sanitizer] App\Models\Customer::$nric uses a Keyed value-definition and is listed in App\Sanitizers\CustomerSanitizer::categorical(). A categorical column is sampled from its distribution, which would override the keyed value — remove it from categorical().
```

## Deterministic keyed values

A normal value definition draws a new random value each time. `Keyed` does the opposite: the same real value always becomes the same replacement. That holds across rows, columns, tables and runs, as long as the key, the namespace and the shape stay the same. The name of the column is never part of the calculation. Use it when the same person or number appears in several places and must still match after sanitizing.

There are two forms. `Keyed::using()` takes a namespace and a shape. `Keyed::pattern()` takes a namespace and a pattern string:

```php
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

public function fields(): array
{
    return [
        'nric'     => Keyed::using('nric', Malaysia::nric()),
        'staff_id' => Keyed::pattern('staff', 'S#####'),
    ];
}
```

`Keyed` needs a secret key. See [Setting the key](#setting-the-key) first. Read [What a keyed value still reveals](#what-a-keyed-value-still-reveals) before you rely on it.

### Setting the key

The key comes from the `PII_SANITIZER_KEY` environment variable. The package reads it through the config key `pii.keyed.key`, which `config/pii.php` defines like this:

```php
'keyed' => [
    'key' => env('PII_SANITIZER_KEY'),
],
```

Generate a key with this command. It prints the key and writes nothing:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Put the output in your `.env` file as `PII_SANITIZER_KEY=base64:...`.

The key can be a raw string or a string that starts with `base64:`. A `base64:` key is decoded the same way Laravel decodes `APP_KEY`. Either way, the key must be at least 32 bytes after decoding.

A raw-string key passes the length check if it is at least 32 bytes, but a phrase that you write by hand can still be guessable. Use the generated key above.

Treat the key like a password:

- Keep it in your uncommitted `.env` file. Never commit it.
- Share it with your teammates out of band, the way you share any other secret.
- The package never logs or prints the key.
- If the key leaks, make a new key and sanitize again from a fresh dump. Data that was sanitized under the old key can still be reversed with the old key (see points 2 and 3 in [What a keyed value still reveals](#what-a-keyed-value-still-reveals)).

The key is needed only when a sanitizer declares a `Keyed` field, including a `Keyed` inside `Json::paths()`. A run with no `Keyed` field never reads it. If the key is missing, is not valid base64, or is too short, the run fails at boot, before any row is read, with this message:

```text
[laravel-pii-sanitizer] A Keyed value-definition is declared but no usable key is configured. Set PII_SANITIZER_KEY (read via pii.keyed.key) to at least 32 bytes, raw or "base64:"-prefixed.
```

### Namespaces and patterns

Every `Keyed` has a namespace. One namespace means one mapping. If two columns hold the same real value and must stay equal after sanitizing, give them the same namespace. In the example above, `'nric'` is the namespace. Two columns in different namespaces map the same input to different outputs.

A namespace must match `/^[A-Za-z0-9_.:-]{1,64}$/`. Anything else fails with this message:

```text
[laravel-pii-sanitizer] A Keyed namespace must match /^[A-Za-z0-9_.:-]{1,64}$/.
```

Every `Keyed` in one namespace must use the same shape with the same parameters. Otherwise the same input would map to different values, so the package fails at boot with this message (the namespace `nric` is an example):

```text
[laravel-pii-sanitizer] Keyed namespace "nric" is declared with different shapes. Every Keyed::using() or Keyed::pattern() in one namespace must use an identical shape and parameters, or the same input would map to different values.
```

`Keyed::using()` accepts only a shape that the package provides: a [`Format::…` helper](#format-preserving-helpers), a [`Malaysia::…` generator](#malaysia-generators), or a pattern. It does not accept a closure or a Faker method name. `Keyed::pattern($namespace, $pattern)` is the short way to wrap a pattern.

A pattern is a string with these characters:

| Character | Meaning |
|---|---|
| `#` | One digit, `0` to `9` |
| `?` | One uppercase letter, `A` to `Z` |
| `*` | One uppercase letter or digit |
| `\` | A backslash makes the next character a plain character, so `\#` is a literal `#` |
| anything else | Written as it is |

A pattern needs at least one of `#`, `?` or `*`, and it cannot end in a single `\`. These are the two messages:

```text
[laravel-pii-sanitizer] A pattern needs at least one placeholder (#, ? or *); a constant has a one-value domain.
[laravel-pii-sanitizer] A pattern cannot end in a dangling "\" escape.
```

What goes in, and what comes out:

- `null` stays `null`. This is true for `Keyed` and for a bare `Format::…` or `Malaysia::…` instance.
- The input can be an integer, a string, a boolean, a backed enum or a `Stringable`. The number `123` and the string `'123'` map to the same output.
- A float or an array is rejected. The message names the type (here, `float`):

```text
[laravel-pii-sanitizer] The keyed value-definition cannot use an input of type float. Supported inputs are null, int, string, bool, a backed enum, or a Stringable.
```

Two cautions for unique columns:

- Do not write a digit pattern that can start with `0`, such as `'#####'`, to an integer unique column. The package compares replacements as text. It does not know that `00417` and `417` are the same number, but an integer column stores both as `417`, so uniqueness can break. Use a string column, or start the pattern with a fixed non-zero digit, such as `'1####'`.
- On a namespace that is bound to a unique column, two different inputs can produce the same candidate. The input that the run meets first keeps it, and the other input moves to the next candidate. So the result for a value that collided can change if your data changes or if you run with `--model` for a subset of models. A value that never collided does not change.

Memory use also grows on unique columns. A namespace that is bound to a unique column keeps one entry in memory for each distinct input, and it also keeps the original values of its unique columns, for the whole run. These entries are in addition to the values that the uniqueness check itself keeps (see [Preserving a column's uniqueness](#preserving-a-columns-uniqueness)). The package reads each such column in full once per run, as a `DISTINCT` scan. Plan for this on very large tables.

### What a keyed value still reveals

A keyed value is not anonymous. Read these seven points before you use it:

1. Without the key, a keyed output does not reveal its input. The calculation is HMAC-SHA256 with a key of at least 32 bytes, which is one-way.
2. With the key, the algorithm and the namespace, someone can recover low-entropy inputs (an NRIC, a phone number, a staff number) by trying every possible input. So the key is as sensitive as the PII it protects.
3. Equal inputs give equal outputs, by design. Equality, joins and how often a value appears all survive. Someone without the key can still see that two rows shared one real value, and can run a frequency analysis.
4. Using one key and one namespace across several datasets links those datasets. Use a different key for each project. Change the key to unlink.
5. The format helpers leak the part they keep (the length, the prefix, the last characters or the email domain). Wrapping a helper in `Keyed` does not hide that part.
6. If you run a keyed sanitizer on data that is already sanitized, it maps the data again, so repeated runs drift. The run still succeeds and values stay unique, but the result is not a fixed point. In maths terms, `P(P(x))` is not `P(x)`. Run a keyed sanitizer once per fresh dump.
7. This package is a development-time tool. It is not a security control. See [It is not a security control](#it-is-not-a-security-control).

## Format-preserving helpers

Some columns must keep their shape: a phone number still looks like a phone number, an email still has a domain. The `Format` helpers replace letters and digits but keep part of the original.

| Helper | What it keeps | What this reveals |
|---|---|---|
| `Format::keepLength()` | The length and every separator (spaces, dashes, dots, `@`). With a one-character mask, such as `keepLength('*')`, every letter and digit becomes that mask. | The length and the separator positions. |
| `Format::keepPrefix($count)` | The first `$count` characters. | Those first characters. |
| `Format::keepLast($count)` | The last `$count` characters. | Those last characters. |
| `Format::keepEmailDomain()` | The `@` and the whole domain. The local part keeps its length and separators. | The domain, and the length of the local part. |

```php
use Shahirul22\LaravelPiiSanitizer\Values\Format;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

public function fields(): array
{
    return [
        'phone' => Format::keepLast(4),
        'email' => Keyed::using('email', Format::keepEmailDomain()),
    ];
}
```

The replaced part follows one rule for each character (a Unicode code point):

- A digit becomes a random digit, `0` to `9`.
- An uppercase letter becomes a random `A` to `Z`.
- Any other letter, including a letter outside the English alphabet, becomes a random `a` to `z`.
- Everything else (separators, punctuation, spaces, symbols) is kept.

Length is counted in characters, not bytes. The rules at the edges:

- `$count` must be at least 1 for `keepPrefix` and `keepLast`.
- A mask must be exactly one character.
- If a value is no longer than `$count`, the whole value is redrawn by the rules above, so the package keeps no part of it. A redrawn character can still equal the old one by chance, and a value that holds only separators comes back as it was.
- `keepEmailDomain()` splits the value at the last `@`. If there is no `@`, or the local part is empty, or the domain is empty, it behaves like `keepLength()` and keeps nothing.
- A value that is not valid UTF-8 is rejected.
- `null` stays `null`.

A bad argument fails when the sanitizer is built, with one of these messages:

```text
[laravel-pii-sanitizer] A keepLength mask must be exactly one UTF-8 code point.
[laravel-pii-sanitizer] keepPrefix needs a count of at least 1.
[laravel-pii-sanitizer] keepLast needs a count of at least 1.
```

For example, `Format::keepLength('*')` turns `Ahmad Ali, 012-3456` into `***** ***, ***-****`. The mask makes the result depend only on the shape of the input.

Used on its own, a helper is random, like a Faker name. On a unique column it goes through the same retry loop as any random definition (100 attempts). Wrapped in `Keyed::using()`, it is deterministic. A masked `keepLength('*')` gives the same output for every value of the same shape, so on a unique column it runs out of attempts as soon as two values have the same shape. Use the unmasked helper or a `Keyed` form there.

A helper can also be called inside a closure, with the same three arguments as any value generator:

```php
'phone' => fn (mixed $value, \Faker\Generator $faker, \Illuminate\Database\Eloquent\Model $row): mixed
    => Format::keepLast(4)($value, $faker, $row),
```

A helper uses the `mb_*` functions, so it needs `ext-mbstring` (see [Requirements](#requirements)).

## Malaysia generators

The `Malaysia` generators make values that look right for Malaysian data: a national identity card number (NRIC), an SST registration number, a state name and a bank-account number. They carry their own data tables, so they do not depend on `app.faker_locale`. You do not need to change that setting.

| Generator | Faker shorthand | What it makes |
|---|---|---|
| `Malaysia::nric(bool $hyphen = false, ?string $gender = null)` | `'malaysiaNric'` | An NRIC number. |
| `Malaysia::sst()` | `'malaysiaSst'` | An SST registration number. |
| `Malaysia::state()` | `'malaysiaState'` | A state name. |
| `Malaysia::bankAccount(?string $bank = null)` | `'malaysiaBankAccount'` | A bank-account number. |

There are two ways to write each one. These are three alternative entries, not one array:

```php
'nric'  => Malaysia::nric(),
'nric'  => Keyed::using('nric', Malaysia::nric()),
'state' => 'malaysiaState',
```

The first is the instance form, random. The second wraps the instance in `Keyed::using()`, so it is deterministic. The third is the Faker shorthand. Wrap the instance in `Keyed::using()` when you need the same input to give the same output. The shorthand string cannot be wrapped.

The formats:

- **NRIC.** `YYMMDDPB###G`, or `YYMMDD-PB-###G` with `hyphen: true`. The birthdate is a real calendar date from 1940-01-01 to 2010-12-31. `PB` is a place-of-birth code, from `01` to `16` or from `21` to `59`. `G` is the last digit: odd for `'male'`, even for `'female'`, and either with `null`. There is no checksum.
- **SST.** `W10` or `B16`, a dash, four digits `YYMM`, a dash, then eight digits. `YYMM` goes from `1809` to `2612`. For example, `B16-2203-48120931`.
- **State.** One of these 16 names:

```text
Johor
Kedah
Kelantan
Melaka
Negeri Sembilan
Pahang
Perak
Perlis
Pulau Pinang
Sabah
Sarawak
Selangor
Terengganu
W.P. Kuala Lumpur
W.P. Labuan
W.P. Putrajaya
```

- **Bank account.** Digits only. The first digit is never `0`. With no bank named, the package picks a bank at random each time, so you get a mix of lengths. The length depends on the bank. There is no checksum.

| Bank | Digits |
|---|---|
| Maybank | 12 |
| CIMB | 14 |
| Public Bank | 10 |
| RHB | 14 |
| Hong Leong | 11 |
| AmBank | 13 |
| Bank Islam | 14 |
| BSN | 16 |

The instance form takes arguments and works everywhere. The two factories that take arguments reject a bad one when the sanitizer is built:

```text
[laravel-pii-sanitizer] Malaysia::nric() gender must be "male", "female" or null.
[laravel-pii-sanitizer] Unknown Malaysian bank "Nobank"; expected one of: Maybank, CIMB, Public Bank, RHB, Hong Leong, AmBank, Bank Islam, BSN.
```

The shorthand strings work only on the Faker that the package gets from the Laravel container, which is the one that sanitizes your data. A Faker you build yourself with `Faker\Factory::create()` does not have these names. The shorthand always uses the default arguments, so `'malaysiaNric'` is always a plain NRIC for any gender, and `'malaysiaBankAccount'` always picks a random bank. To pass arguments, use the instance form, for example `Malaysia::nric(true, 'female')` or `Malaysia::bankAccount('Maybank')`.

About `null`: the instance form keeps `null` as `null`. The shorthand ignores the current value, like any other Faker method name, so it also fills a `NULL` cell with a new value. If a column has `NULL` values that must stay `NULL`, use the instance form.

These formats are assumptions. The NRIC place-of-birth codes and birthdate range, the SST format, and the account length for each bank were not checked against the official sources (the Malaysian National Registration Department, the Royal Malaysian Customs Department or the banks). The values look right, but do not treat them as verified, and do not use them to test code that must accept only real numbers.

## JSON columns

A JSON column holds a whole document, and only some parts of it are PII. `Json::paths()` lets you rewrite just those parts. You name each part with a path. The rest of the document stays as it was.

### Declaring paths

Put `Json::paths()` in `fields()`, under the name of the JSON column. Its argument maps each path to a value definition. Every path is relative to that column's document:

```php
use Shahirul22\LaravelPiiSanitizer\Values\Format;
use Shahirul22\LaravelPiiSanitizer\Values\Json;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

public function fields(): array
{
    return [
        'preferences' => Json::paths([
            'contact->phone' => Format::keepLast(4),
            'contact->email' => Keyed::using('email', Format::keepEmailDomain()),
            'emergency->*->name' => 'name',
        ]),
    ];
}
```

Take this document in the `preferences` column:

```json
{"theme":"dark","contact":{"phone":"0123456789","email":"ali.baba@example.com"},"emergency":[{"name":"Siti Aminah","relation":"sister"},{"name":"Raj Kumar","relation":"friend"}]}
```

After the run it looks like this. The new values are random, so yours will differ:

```json
{"theme":"dark","contact":{"phone":"8173646789","email":"ynh.datx@example.com"},"emergency":[{"name":"Owen Kub","relation":"sister"},{"name":"Felicity O'Hara","relation":"friend"}]}
```

Only the declared paths change. Every other key, the nesting and the type of every other value stay the same. `theme` and `relation` are untouched.

A path value can be any [value definition form](#value-definition-forms). That includes a static value, a closure, a `ValueGenerator` class, a Faker method name, and every helper in this README: [`Keyed`](#deterministic-keyed-values), [`Format`](#format-preserving-helpers) and [`Malaysia`](#malaysia-generators). The definition receives the current value at that path. A `Keyed` path and a flat `Keyed` column that share one namespace give the same output for the same input. If a path uses `Keyed`, the key must be set (see [Setting the key](#setting-the-key)).

A definition must return something JSON can hold: `null`, a boolean, an integer, a string, a finite float, an enum, or an array of these. A backed enum is written as its value. A unit enum is written as its name. Anything else, for example a `DateTime`, stops the chunk. The message names the column and the path (the names are examples):

```text
[laravel-pii-sanitizer] $preferences: path contact->when: The value definition resolved to a DateTime, which cannot be written into JSON. Return null, a scalar, an array or an enum.
```

`Json::paths()` checks its argument when you call it. It needs at least one path. A path cannot be another `Json::paths()`. Two paths cannot overlap, which means a path cannot be the start of another path, and a `*` overlaps any key at the same position. Each of these messages is an example for one bad call:

```text
[laravel-pii-sanitizer] Json::paths() needs at least one path.
[laravel-pii-sanitizer] Json::paths() path "a" is itself a Json::paths() definition; nesting is not supported.
[laravel-pii-sanitizer] Json::paths() paths "a->b" and "a->*" overlap; each JSON location may be declared once.
```

A `Json::paths()` column cannot take part in a `mirrors()` group. A mirror must be a plain `Keyed` definition, and a `Json::paths()` column is not one, even when a `Keyed` sits inside it.

### Path grammar

A path is a list of segments joined by `->`. Each segment is one of these:

- A key. It matches the object member with exactly that name.
- A whole number, such as `0` or `12`. It matches the array element at that index, counting from 0. It also matches an object member with that name.
- `*`. It matches every array element or every object member, in document order.

Some examples:

| Path | What it targets |
|---|---|
| `contact->phone` | The `phone` member of the `contact` object. |
| `emergency->0->name` | The `name` of the first element of `emergency`. |
| `emergency->*->name` | The `name` of every element of `emergency`. |
| `tags->*` | Every element of `tags`. |

A number must be a whole number with no sign and no leading zero. `01` and `-1` are not indexes. They are plain keys, so they match only an object member with that name, never an array element.

The package rejects these paths when you call `Json::paths()`:

- A segment with a bracket index, such as `a[0]` or `a[]`. Any segment that contains `[`, optional digits and `]` is rejected. Write `a->0` or `a->*` instead.
- An empty segment, such as `a->` or `a->->b`.

```text
[laravel-pii-sanitizer] Json::paths() path "a[0]" uses a bracket index in segment "a[0]"; write ->0 or ->* instead.
[laravel-pii-sanitizer] Json::paths() path "a->" has an empty segment.
```

Some keys cannot be addressed at all. A key that contains `->` is read as two segments. A key that is exactly `*` is read as the wildcard. An empty key is an empty segment. A key with `[`, optional digits and `]` in it is rejected. You cannot rewrite such a key with `Json::paths()`.

### Absent and null paths

One rule covers every case where a path has nothing to rewrite:

```text
A declared path is skipped for a row when its target is absent or null: the column value is NULL or the empty string, the value at a segment that must descend is not a JSON object or array, a key or index on the way is missing, or the value found is JSON null. A skipped path's definition is not called, nothing is written for it, no structure is created and no error is raised. Every other declared path in the same row is still applied. A wildcard segment applies this rule to each matched element on its own; a wildcard over an empty array or object matches nothing.
```

In the example above, a row whose `preferences` is `{"theme":"light"}` is left as it is, and so is a row where `preferences` is `NULL`. `emergency->*->name` skips an element that has no `name`, or whose `name` is `null`, and still rewrites the elements that have one.

Bad JSON is a different case, and it depends on the column:

- **No cast.** The column holds JSON text. If the text is not valid JSON, the chunk stops and nothing in that chunk is written. The message names the column and does not print the value:

  ```text
  [laravel-pii-sanitizer] $preferences: The column value is not a valid JSON document, so its declared paths cannot be rewritten.
  ```

- **An array cast** (`array`, `json`, `json:unicode`, `encrypted:array` or `encrypted:json`). Laravel reads an empty or malformed JSON text as `null`. The rule above then skips every path. But the column is re-encoded and written for every row (see [Supported columns and carrier limits](#supported-columns-and-carrier-limits)), so that `null` is written back as `NULL`. An empty or malformed JSON text under an array cast is written back as `NULL`. The original text is lost. If the column is `NOT NULL`, the write is refused and the chunk is rolled back (see below).

### Supported columns and carrier limits

A `Json::paths()` column must meet all of these rules. The package checks them at boot, before it reads any row:

- It has no get mutator and no set mutator.
- Its cast is one of: none, `array`, `json`, `json:unicode`, `encrypted:array`, `encrypted:json`.
- Its column type is a JSON type or a string type, such as `json`, `jsonb`, `varchar` or `text`.

The `object`, `collection`, `encrypted:object` and `encrypted:collection` casts are not supported yet. Neither are `AsArrayObject`, `AsCollection` and other class casts. The package rejects them. This is the message, with example names. It ends with the reason:

```text
[laravel-pii-sanitizer] App\Models\User::$preferences on table "users" cannot take a Json::paths() definition in App\Sanitizers\UserSanitizer::fields(): its cast "object" is not one of: none, array, json, json:unicode, encrypted:array, encrypted:json.
```

The other two reasons replace the text after the last colon (the type name is an example):

```text
it has a get or set mutator
its column type family is "integer", not json or string
```

The cast decides how the package sees the document. This gives two carriers, and each has limits.

**A string carrier (no cast).** The package reads the JSON text, changes the declared paths, and writes the text again. If no path matched in a row, the original text is kept as it is. If at least one path matched, the document is written again with the package's own encoding. The encoding does not keep:

- The spaces and new lines between tokens. They are removed.
- The escape form. `\u00e9` becomes `é` and `\/` becomes `/`.
- Integers that do not fit in 64 bits. They become floats, so `12345678901234567890` becomes `1.2345678901234567e+19`.

`{}` stays `{}`, and `1.0` stays `1.0`.

**An array carrier (an array cast).** Laravel turns the column into a PHP array, the package changes it, and the cast writes it back. The cast decides the final text, so the package cannot keep the original shape. An empty object `{}` is stored as `[]`. A number such as `1.0` is stored as `1`. An object whose keys are exactly `0`, `1`, … `n-1` is stored as a list.

The package writes every declared column of every row in a chunk, even when the new value equals the old one. An array-cast column is also re-encoded for every row, including rows where no path matched. For `encrypted:array` and `encrypted:json`, every ciphertext changes. If you need untouched rows to stay byte for byte the same, use a column with no cast.

Four more rules apply:

- **No categorical.** A `Json::paths()` column cannot be listed in `categorical()`. Sampling would put another row's whole document in its place. The package rejects it at boot (see [Preserving a column's value distribution](#preserving-a-columns-value-distribution)). The message looks like this (the names are examples):

  ```text
  [laravel-pii-sanitizer] App\Models\User::$preferences uses a Json::paths() definition and is listed in App\Sanitizers\UserSanitizer::categorical(). Sampling would replace the whole document with another row's value, so remove it from categorical().
  ```

- **A static value must be JSON-writable.** A static path value is checked at boot. It must be `null`, a scalar, an array or an enum. If it is an object such as a `DateTime`, the run stops before it reads any row:

  ```text
  [laravel-pii-sanitizer] The static value for path "contact->when" of App\Models\User::$preferences in App\Sanitizers\UserSanitizer::fields() is a DateTime, which cannot be written into JSON. Use null, a scalar, an array or an enum.
  ```

- **Constraints name the column, not the path.** The package checks the new value of the whole column against its constraints, as it does for any column. So an error from that check names the column and never a path. For example, an array-cast column that is `NOT NULL` and holds an empty text (see [Absent and null paths](#absent-and-null-paths)) stops the chunk with this message (the names are examples):

  ```text
  [laravel-pii-sanitizer] Cannot sanitize App\Models\User::$preferences on table "users": the replacement value is null but the column is NOT NULL (nullability constraint). Return a non-null value from its definition in fields().
  ```

- **A unique index over the whole column.** The whole document is the value that must be unique. It goes through the same retry loop as any random definition (100 attempts). A row where no path matched keeps its own document. That document is already taken, so the run stops with a `UniquenessExhaustedException`. A `Keyed` path always gives the same result for the same input, so retrying cannot fix a collision that it causes.

## Cast-aware writes and column constraints

This section covers how the package writes cast columns ([Cast and mutator columns](#cast-and-mutator-columns)) and how it checks values against column rules before it writes ([Column-constraint validation](#column-constraint-validation)).

### Cast and mutator columns

A model can change a value before it reaches the database. It can have a cast, such as `encrypted`, or a set mutator. If you list such a column in `fields()`, the package does not write your new value as it is. It first passes the value through the model's own cast or mutator, on a throwaway copy of the row. Then it writes the result, which is exactly what Eloquent itself would have stored. So when your application reads the column back through the model, it gets the new value. For example, an `encrypted` column stays decryptable.

A column with no cast and no set mutator is written as before. The package writes a chunk with batched `UPDATE` statements, which change many rows in one statement, not one statement per row. A cast column uses the same batched statements. A table that has both kinds of column is still written in batches.

One statement is capped at 400 bound parameters. A row takes `(columns × (identity columns + 1)) + identity columns` of them, where `columns` is the number of columns you declare and `identity columns` is the size of the paging identity. So a sanitizer with many declared columns, or a table with a composite identity, puts fewer rows in each statement and issues more statements per chunk. For example, with one identity column and three declared columns, a row takes 7 parameters and a statement holds 57 rows, so a chunk of 1000 rows is written in 18 statements.

The package refuses a cast column in two cases. In both, it rolls back the chunk, so nothing in that chunk is written, and the run stops. Chunks that finished earlier stay written. Both messages use example names here.

The cast or mutator also writes to other attributes of the row. The package did not ask you to sanitize those, so it will not write them:

```text
[laravel-pii-sanitizer] Cannot sanitize App\Models\Customer::$profile on table "customers": its cast/mutator also writes to other attributes (profile_hash), which were not declared for sanitization. Remove $profile from the sanitizer's fields() or replace the multi-attribute cast.
```

The cast or mutator fails to encode the new value. The text in brackets is the class name of the error, never its message. In this example the model has an `encrypted` cast and the application has no `APP_KEY`:

```text
[laravel-pii-sanitizer] Cannot sanitize App\Models\Customer::$ssn on table "customers": the model's cast/mutator failed to encode the replacement value (Illuminate\Encryption\MissingAppKeyException). Check the cast's requirements (e.g. APP_KEY for encrypted casts) or remove the column from fields().
```

A dry run does the same encoding. It shows both failures and writes nothing.

**Encrypted unique columns.** Uniqueness tracking is not meaningful on an encrypted unique column. The values already in such a column are ciphertext, and each encryption of the same text gives a different ciphertext. So the package cannot compare a new value with the values that are already stored. Do not rely on the package to keep an encrypted column unique. See [Preserving a column's uniqueness](#preserving-a-columns-uniqueness) for how uniqueness works on other columns.

### Column-constraint validation

Before the package writes a value, it checks the value against the rules of the column in the database. It checks four things:

- The type of the column, such as integer, decimal, boolean, date or time, JSON or string.
- The maximum length, counted in characters.
- The allowed values, for an `enum` or `set` column.
- Whether the column allows `NULL`.

The package never changes a value to make it fit. It does not cut a long value short. It does not replace `NULL` with another value. It does not pick a new value. If a value breaks a rule, the package stops and tells you which column, so you can fix the value definition.

The check is made on the value as it will be stored, after the cast. For an `encrypted` column, the length that counts is the length of the ciphertext, which is longer than the text.

When the check runs depends on the value definition:

- A static value on a plain column is checked once at boot, before any row is read. A plain column has no cast, no set mutator, and is not listed in `categorical()`. A mistake in a static value stops the run at once, with a `ConstraintViolationException`.
- Every other definition is checked per row, just before the write. This covers Faker names, closures, generators, `categorical()` columns and cast columns. A violation rolls back that chunk and stops the run with a `ConstraintViolationException`. Chunks that finished earlier stay written.

`--dry-run` runs the same encoding and the same checks, so it shows these failures without writing anything.

These are the four messages, with example names. Each one tells you what to change:

```text
[laravel-pii-sanitizer] Cannot sanitize App\Models\Customer::$phone on table "customers": the replacement value is null but the column is NOT NULL (nullability constraint). Return a non-null value from its definition in fields().
```

```text
[laravel-pii-sanitizer] Cannot sanitize App\Models\Customer::$phone on table "customers": the replacement value is 32 characters but the column allows at most 20 (length constraint; for a cast-bearing column such as encrypted this is the stored, encoded length). Shorten the value its definition in fields() produces.
```

```text
[laravel-pii-sanitizer] Cannot sanitize App\Models\Customer::$status on table "customers": the replacement value is not in the column's allowed set (enum/set constraint: active, closed). Return one of the allowed values from its definition in fields().
```

```text
[laravel-pii-sanitizer] Cannot sanitize App\Models\Customer::$age on table "customers": the replacement value does not match the column's integer type (type constraint). Return a value of that type from its definition in fields().
```

Which of these checks work depends on your database driver. See [Database driver notes](#database-driver-notes).

## Primary keys and table targets

This section covers which columns the package can page on ([Composite, UUID and keyless primary keys](#composite-uuid-and-keyless-primary-keys), [Declaring a paging key](#declaring-a-paging-key)) and how to sanitize a table that has no Eloquent model ([Tables without a model](#tables-without-a-model)).

### Composite, UUID and keyless primary keys

A table can have a composite primary key, a UUID or other string primary key, or no primary key at all. All three work.

To read a table in pages, the package needs a paging identity. This is a column, or a set of columns, that tells the rows apart, is never `NULL`, and is never rewritten by the run. The package finds it at boot, before it reads any row. It tries these sources in order and uses the first one that fits:

1. The primary key. For a composite primary key, this means all of its columns. The package also accepts the model's own key column when the table has it.
2. The first unique index whose columns are all `NOT NULL` and none of which is listed in `fields()`.
3. The columns that you declare in `pagingKey()`. See [Declaring a paging key](#declaring-a-paging-key).
4. Every `NOT NULL` column that is not listed in `fields()`. The package leaves out `json` columns, `binary` columns and decimal or float columns, such as `decimal`, `numeric`, `float`, `double` and `real`.

Sources 3 and 4 depend on your data, not only on the schema. So at boot the package runs a query to check that the columns are unique per row.

A single-column identity, such as an integer `id` or a UUID, is read with Laravel's `chunkById()`. A UUID or other string key is kept as text, so an id made only of digits is not turned into an integer. A multi-column identity is read with a key-set query. Each page starts after the last row of the previous page, and the package never uses `OFFSET`. A key-set query can be slower than single-column paging. (One more case uses the key-set query: a single identity column that has a cast or a get mutator and is not the model's key.)

Every column of a composite primary key is protected. If you list any one of them in `fields()`, the package rejects it at boot, not only the first column.

A table with no primary key and no unique index can still work, through source 3 or 4. If its rows are completely identical, no column set tells them apart. The package cannot page that table. It stops at boot with an `UnpageableTableException`, before it writes anything.

### Declaring a paging key

`Sanitizer::pagingKey()` returns a list of column names. The default is an empty list, which means that you declare nothing. The columns must be `NOT NULL`, unique per row, and not listed in `fields()`. The package checks all three at boot.

This example is a pivot table `role_user`. It has the `NOT NULL` columns `user_id`, `role_id` and `note`. It has no primary key and no unique index. The pair `user_id` and `role_id` is unique in the data. The sanitizer rewrites only `note`:

```php
<?php

namespace App\Sanitizers;

use Shahirul22\LaravelPiiSanitizer\Sanitizer;

class RoleUserSanitizer extends Sanitizer
{
    public function fields(): array
    {
        return ['note' => 'sentence'];
    }

    public function pagingKey(): array
    {
        return ['user_id', 'role_id'];
    }
}
```

On this small table, source 4 would find the same two columns. Declaring the key is still useful. If the table had more `NOT NULL` columns, source 4 would use all of them, which makes a wider key. Source 4 also skips `json`, `binary` and decimal columns, and a declared key is not subject to that rule.

If your declared key does not meet the rules, the package does not use it. It goes on to source 4. If no source fits, the run stops at boot with an `UnpageableTableException`. This is the message, with example names. For a table target, the class shown is the package's own `TableRow` class:

```text
[laravel-pii-sanitizer] Cannot page role_user (Shahirul22\LaravelPiiSanitizer\TableRow, sanitized by App\Sanitizers\RoleUserSanitizer): no primary key, no NOT NULL unique index disjoint from fields(), and no column set proven unique at boot. Declare App\Sanitizers\RoleUserSanitizer::pagingKey() with NOT NULL columns that uniquely identify each row and are not in fields().
```

### Tables without a model

Some tables have no Eloquent model. A many-to-many pivot table is the usual case. List such a table in `pii.tables`. This key maps a table name to a sanitizer class. Put it in `config/pii.php`:

```php
'tables' => [
    'role_user' => \App\Sanitizers\RoleUserSanitizer::class,
],
```

The sanitizer in the example above fits this entry.

These rules apply to table targets:

- The map is the only way to register a table. There is no lookup by convention, because a table has no class name to match.
- Tables run after the models in `pii.models`. They run in the order of the map.
- `--model` skips every table target. A run with `--model` never touches `pii.tables`.
- In the command output, a table target is labelled `table:role_user`. When you do not use `--model`, the count in `Sanitizing N model(s).` includes the table targets.
- A table target has no model, so it has no casts and no mutators.
- The same safety rules apply as for a model. For example, the package rejects a foreign-key column in `fields()`, and `--dry-run` writes nothing.

If `pii.tables` is not a map of table names to sanitizer class names, the run stops with this message:

```text
[laravel-pii-sanitizer] pii.tables must be a map of table-name => Sanitizer class-name strings.
```

## Database driver notes

The package checks column rules on SQLite, MySQL, MariaDB and PostgreSQL. What it can check, and how far it has been tested, depends on the driver.

### Column-constraint checks by driver

The type check and the `NULL` check run on all four drivers. The other two checks differ:

| Driver | Maximum length | Allowed values |
|---|---|---|
| MySQL and MariaDB | Checked for `char` and `varchar` columns | Checked for `enum` and `set` columns |
| PostgreSQL | Checked for character columns that have a length, such as `varchar(20)` | Not checked. The package does not read PostgreSQL enum sets. |
| SQLite | Not checked. SQLite does not enforce a declared length. | Checked for an enum, which the package reads from the `CHECK` constraint |
| Any other driver | Not checked | Not checked |

On any other driver, the package checks only whether the column allows `NULL`. A value that breaks a rule that the package does not check is not stopped by the package. The database may then reject it, or accept it.

### What was verified, and what was not

The tests of this package run on SQLite. The statements below have been tested only with stubs and with SQLite. They have not been run against a real MySQL, MariaDB or PostgreSQL server.

When you opt a referenced column in with `mirrors()` and it takes part in a foreign key, the package switches foreign-key enforcement off for the run and restores the setting afterwards. It uses these statements:

```text
SQLite:          PRAGMA foreign_keys = OFF, then PRAGMA foreign_keys = ON
MySQL, MariaDB:  SET FOREIGN_KEY_CHECKS=0, then SET FOREIGN_KEY_CHECKS=1
PostgreSQL:      SET session_replication_role = 'replica', then back to 'origin' or 'local'
```

The MySQL, MariaDB and PostgreSQL statements are verified only against stubs and SQLite.

The package writes each chunk with batched `UPDATE` statements, each capped at 400 bound parameters (see [Cast and mutator columns](#cast-and-mutator-columns)), and a new value goes into them as a bound parameter inside a `CASE` expression. For a `json` or `jsonb` column on PostgreSQL, the package has not confirmed that PostgreSQL accepts this assignment. This applies to a `Json::paths()` column and to an array-cast JSON column. The PostgreSQL `json` and `jsonb` batched writes are verified only against stubs and SQLite.

Before you rely on any of this on MySQL, MariaDB or PostgreSQL, run it on a copy of your database first.

## Referenced columns

By default the package refuses to rewrite a foreign-key column, a column that a foreign key references, or a primary-key column (see [Unsafe columns are rejected before a single row is read](#unsafe-columns-are-rejected-before-a-single-row-is-read)). Sometimes the same value must change in several places at once and must still match afterwards, for example a customer's NRIC in `customers.nric` and in `orders.customer_nric`. This section shows how to opt such a column in ([Opting a column in with mirrors()](#opting-a-column-in-with-mirrors)), what you must declare yourself ([You must declare every mirror column](#you-must-declare-every-mirror-column)), what the package does to foreign-key enforcement during the run ([Foreign-key enforcement during the run](#foreign-key-enforcement-during-the-run)), what your database must allow ([Database requirements and side effects](#database-requirements-and-side-effects)) and the extra rule for a primary-key column ([Opting in a primary-key column](#opting-in-a-primary-key-column)).

### Opting a column in with mirrors()

`Sanitizer::mirrors()` returns a map. Each key is a column of this sanitizer's table. Each value is a list of the other columns that hold the same value, written as `table.column`. Writing an entry is the opt-in for that column. The default is an empty array, which means no opt-in.

The example below uses two sanitizers and one `Keyed` namespace, `nric`. It assumes this schema:

- `customers` has a surrogate primary key `id` and a `NOT NULL` unique column `nric`.
- `orders` has a primary key `id` and a column `customer_nric` with a foreign key to `customers.nric`.

With this schema, the package pages each table on its `id` column, which is not in `fields()`, so no `pagingKey()` is needed.

```php
use Shahirul22\LaravelPiiSanitizer\Sanitizer;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;
use Shahirul22\LaravelPiiSanitizer\Values\Malaysia;

class CustomerSanitizer extends Sanitizer
{
    public function fields(): array
    {
        return ['nric' => Keyed::using('nric', Malaysia::nric())];
    }

    public function mirrors(): array
    {
        return ['nric' => ['orders.customer_nric']];
    }
}

class OrderSanitizer extends Sanitizer
{
    public function fields(): array
    {
        return ['customer_nric' => Keyed::using('nric', Malaysia::nric())];
    }

    public function mirrors(): array
    {
        return ['customer_nric' => ['customers.nric']];
    }
}
```

Both `Keyed::using('nric', Malaysia::nric())` definitions use the same namespace and the same shape, so one real NRIC becomes one new NRIC in both tables. Both models must be in the same run, for example both in `pii.models`. These rules apply:

- Every column in the group is declared in `fields()` of its own sanitizer. Each one must be a bare `Keyed` definition. A `Keyed` value inside [`Json::paths()`](#json-columns) does not count, and a `Json::paths()` column can never be a mirror.
- All the columns in the group use one `Keyed` namespace, with the same shape and parameters.
- Each column that the package would reject by default needs its own `mirrors()` entry. In the example, `customers.nric` is referenced by a foreign key and `orders.customer_nric` is a foreign key, so each sanitizer has an entry.
- A copy of the value that has no foreign key and that the package would not reject needs only a `Keyed` field in the same namespace. It does not need a `mirrors()` entry.
- `null` stays `null`.
- Each column is declared in `fields()` by exactly one target of the run.
- Write a table name as the model's `getTable()` returns it.

The package checks a `mirrors()` entry at boot, before it reads any row. A bad entry stops the run. An entry can be invalid for four reasons, and these are the four messages (the class, column and table names are examples):

```text
[laravel-pii-sanitizer] App\Sanitizers\CustomerSanitizer::mirrors() entry for $nric is invalid: the column is not declared in fields().
[laravel-pii-sanitizer] App\Sanitizers\CustomerSanitizer::mirrors() entry for $nric is invalid: the mirror list is empty.
[laravel-pii-sanitizer] App\Sanitizers\CustomerSanitizer::mirrors() entry for $nric is invalid: mirror "orders" is not of the form table.column.
[laravel-pii-sanitizer] App\Sanitizers\CustomerSanitizer::mirrors() entry for $nric is invalid: mirror "customers.nric" names the column itself.
```

A column in `mirrors()` that is not a `Keyed` definition is refused, because only `Keyed` guarantees that a column and its mirrors get the same replacement:

```text
[laravel-pii-sanitizer] App\Models\Customer::$nric is opted in through App\Sanitizers\CustomerSanitizer::mirrors() but its definition is not a Keyed value. Only Keyed::using() or Keyed::pattern() guarantees that a column and its mirrors receive the same replacement.
```

A mirror column that two targets of the run both declare is refused, and so are two mirrors that use different namespaces:

```text
[laravel-pii-sanitizer] Mirror column "orders.customer_nric" is declared in fields() by more than one sanitize target in this run. A mirror column must be sanitized by exactly one target.
[laravel-pii-sanitizer] "customers.nric" and "orders.customer_nric" are declared as mirrors but use different Keyed namespaces ("nric" and "order-nric"). Mirrors must share one namespace so the same value maps to the same replacement.
```

For the key that `Keyed` needs, see [Setting the key](#setting-the-key).

### You must declare every mirror column

The package does not discover the reference graph. It does not look at your foreign keys to decide which columns belong together. You must declare every mirror column yourself, in `mirrors()` for a column that the package would reject, and with a `Keyed` field in the same namespace for a plain copy. The package never adds a mirror on its own.

What happens when your declaration is incomplete depends on the kind of link:

- **A foreign-key link to a column you did not declare.** The package reads foreign-key metadata only to refuse the run, never to add a column. If an opted-in column is linked by a foreign key to a column that is not in its group, the run stops at boot. The message names the foreign key (the names are examples):

  ```text
  [laravel-pii-sanitizer] App\Models\Customer::$nric is opted in through App\Sanitizers\CustomerSanitizer::mirrors(), but the foreign key orders.customer_nric -> customers.nric links it to a column that is not declared as a mirror. Declare that column as a mirror and sanitize it with the same Keyed namespace, or remove the opt-in. The package never adds a mirror on its own.
  ```

- **A copy of the value that has no foreign key.** The package cannot see such a copy, for example a `tickets.holder_nric` column that holds the same NRIC as text. If you leave it out, the run succeeds and does not warn you. The copy keeps the original NRIC while the other columns get a new one. **An incomplete declaration silently yields inconsistent references.** Find every column that holds the value and declare it.

Every mirror that you declare must also be sanitized in the same run. If it is not, the run stops at boot. For example, `--model` skips every model that you did not name and every `pii.tables` entry, so it can leave out a mirror. The message says so (the names are examples):

```text
[laravel-pii-sanitizer] App\Sanitizers\CustomerSanitizer::mirrors() declares "orders.customer_nric" as a mirror of $nric, but no sanitize target in this run declares that column in fields(). Declare it in its table's sanitizer with the same Keyed namespace, and do not exclude that target (for example with --model, which also skips pii.tables).
```

### Foreign-key enforcement during the run

Each chunk is written in its own transaction, so a parent table and the tables that refer to it are never rewritten in one step. A database that enforces foreign keys would refuse the first write. So when an opted-in group has a foreign key that touches it, the package switches foreign-key enforcement off on the run's connection for the run. It switches enforcement on again at the end, also when the run fails. Because enforcement is off, an `ON UPDATE CASCADE` rule does not fire either. Each column is rewritten exactly once, by its own sanitizer.

When the package really changed the setting, the command prints this line after the progress bars:

```text
Foreign-key enforcement was suspended for this run because opted-in referenced columns were rewritten.
```

The line does not appear in these cases:

- The run is a `--dry-run`. A dry run checks that suspension is possible, but it never leaves enforcement off. On PostgreSQL it sets `session_replication_role` to `replica` for a moment to prove that the role may do so, and then sets it back (see [Database requirements and side effects](#database-requirements-and-side-effects)).
- No opted-in group has a foreign key.
- Enforcement was already off before the run. The package then changes nothing and has nothing to restore.

If the run fails part-way, and the package had switched enforcement off, the command also prints this warning:

```text
Foreign-key enforcement was suspended and the run stopped part-way, so referenced columns and their mirrors may now disagree. Restore the database dump and run again: re-running on partly sanitized data does not repair them.
```

Take this warning literally. Chunks that finished before the failure stay written, and while enforcement was off, the database did not check that the references still matched. Restore your database dump, fix the cause, and run again from the start. A second run on the partly sanitized data does not repair the references. It maps the data again (see [What a keyed value still reveals](#what-a-keyed-value-still-reveals), point 6).

### Database requirements and side effects

The suspension needs support from your database. The package checks this at boot, before it reads any row. A `--dry-run` runs the same check, so it shows a refusal too. If suspension is not possible, the run stops with this message, where the text after the last colon is one of the three reasons below (the PostgreSQL reason is shown):

```text
[laravel-pii-sanitizer] Opted-in referenced columns need foreign-key enforcement suspended for this run, which is not possible here: the PostgreSQL role may not set session_replication_role (a superuser, or on PostgreSQL 15+ a GRANT SET ON PARAMETER session_replication_role, is required).
```

```text
the SQLite connection is inside an open transaction, where PRAGMA foreign_keys cannot change
the PostgreSQL role may not set session_replication_role (a superuser, or on PostgreSQL 15+ a GRANT SET ON PARAMETER session_replication_role, is required)
the "oracle" driver is not supported for opted-in referenced columns
```

In the third reason, `oracle` is an example. It is the name of your driver.

- **PostgreSQL privilege.** The package sets `session_replication_role` to `replica`. This needs a superuser, or, on PostgreSQL 15 and later, `GRANT SET ON PARAMETER session_replication_role` for the database role that you use. A dry run sets the role to `replica` for a moment and sets it back, to prove that it can.
- **PostgreSQL trigger side effect.** The `replica` setting also stops ordinary triggers for the run, not only foreign-key checks. Triggers that are `ENABLE ALWAYS` or `ENABLE REPLICA` still fire. The notice above names only foreign keys, so remember this when your tables have triggers, for example audit triggers or triggers that keep a copy of a value up to date. If the connection is dropped and opened again in the middle of the run, the setting is lost, the next chunk fails and the run stops.
- **SQLite.** SQLite cannot switch enforcement off inside an open transaction. This is the case when a test uses `RefreshDatabase` or `DatabaseTransactions`. If the connection is inside a transaction and enforcement is on, the run is refused at boot. If enforcement is already off, nothing is refused.
- **Other drivers.** Only SQLite, MySQL, MariaDB and PostgreSQL are supported. Any other driver is refused.

The MySQL, MariaDB and PostgreSQL statements have been checked only against stubs and SQLite. See [What was verified, and what was not](#what-was-verified-and-what-was-not) and [Database driver notes](#database-driver-notes).

### Opting in a primary-key column

A primary-key column can be opted in, like any other referenced column. But the package pages through a table by its identity, and it must never rewrite the columns it pages on. So a primary-key column can be opted in only when the table has another way to tell its rows apart: a paging identity that is not in `fields()`. The package looks for one in the order that [Composite, UUID and keyless primary keys](#composite-uuid-and-keyless-primary-keys) describes. It ignores the primary key, because that key is being rewritten.

If none of the other sources fits, the run stops at boot with this message (the table, class and column names are examples):

```text
[laravel-pii-sanitizer] Cannot page order_items (App\Models\OrderItem, sanitized by App\Sanitizers\OrderItemSanitizer): its primary-key column $customer_nric is opted in through mirrors() and will be rewritten, and no other NOT NULL unique column set disjoint from fields() was found. Declare App\Sanitizers\OrderItemSanitizer::pagingKey(), or remove the opt-in.
```

This happens, for example, when the only unique key of the table is a composite key `(customer_nric, product_id)` and you opt in `customer_nric`. Declare a [paging key](#declaring-a-paging-key) with `NOT NULL` columns that are unique per row and are not in `fields()`, or remove the opt-in.

A paging identity of several columns is read with a key-set query, which can be slower than the single-column paging that a plain `id` column gets.

## Known limitations

- **Referenced and primary-key columns are rejected by default.** A foreign-key column, a column that another table's foreign key references, and a primary-key column are rejected at sanitizer-resolution time, before any row is read. You can opt in one column at a time with `mirrors()` and a `Keyed` definition. See [Referenced columns](#referenced-columns).
- **A same-table reference is not rejected by default.** A column that is referenced only by a foreign key on its own table is not rejected as a referenced column. For example, `employees.manager_staff_id` is a foreign key to `employees.staff_id`. The column `manager_staff_id` is rejected as a foreign key, but `staff_id` is not rejected as a referenced column (unless it is also the primary key). If you put `staff_id` in `fields()` without `mirrors()`, the run starts, and the database can refuse the write part-way through. If you want to sanitize it, declare it and `manager_staff_id` through `mirrors()` with one `Keyed` namespace.
- **No reference-graph discovery.** The package never works out which columns hold the same value. You declare every mirror column yourself, and an incomplete declaration silently yields inconsistent references. See [You must declare every mirror column](#you-must-declare-every-mirror-column).
- **No file or binary PII, and not a security control.** See [What this package does not do](#what-this-package-does-not-do).
- **Single default connection.** Only the default database connection is supported and tested. A model that sets its own `$connection` is read and written on that connection, so do not list such models.
- **No model auto-discovery.** `pii.models` and `pii.tables` are explicit lists; nothing is discovered by scanning the filesystem.
- **Development-time only.** There is no production story, by design.

The v1 guarantees still hold. The environment guard and `--force` work as before. Chunking is automatic and you can override it. Each chunk runs in its own transaction, and a failure is reported. `--dry-run` writes nothing. Running it again on data that is already sanitized does not repair or undo anything. It sanitizes the data again, and a `Keyed` value is mapped again, so the result is not the same as after the first run (see [What a keyed value still reveals](#what-a-keyed-value-still-reveals), point 6). A sanitizer is found by convention, and `pii.sanitizers` overrides it. The value types, uniqueness preservation and distribution preservation are unchanged. Features that are planned but not yet shipped are in the [Roadmap](#roadmap).

## Roadmap

These items are planned or upcoming. They are not shipped. Do not rely on them.

- Sanitizing surrogate key values, for example the integer `id` columns that foreign keys point to, with the package finding and rewriting every reference itself. Today you can opt in a natural-key PII column (such as an NRIC) with `mirrors()`, and you declare every mirror yourself.
- Auto-discovery of the reference graph, so that you no longer declare every mirror column by hand.
- Support for more than one database connection.
- A rule-discovery command, which would suggest sanitizer rules for your schema.
- A run-time coverage warning for columns that probably hold PII but that no sanitizer declares.

## License

MIT.
