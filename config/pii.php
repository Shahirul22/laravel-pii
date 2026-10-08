<?php

return [

    /*
    |--------------------------------------------------------------------
    | Sanitizer overrides
    |--------------------------------------------------------------------
    |
    | Explicit model => sanitizer class mappings. Use this when a model's
    | sanitizer doesn't follow the default convention (App\Sanitizers\{Model}Sanitizer),
    | e.g. namespaced models, third-party models, or to override the
    | convention-resolved sanitizer entirely. An entry here always takes
    | precedence over the convention for the same model.
    |
    | Example:
    |   \App\Models\User::class => \App\Sanitizers\CustomUserSanitizer::class,
    |
    */
    'sanitizers' => [],

    /*
    |--------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------
    |
    | The explicit, ordered list of model classes the engine walks. There
    | is no filesystem auto-discovery in v1 -- an empty list simply yields
    | an empty run report rather than an error.
    |
    */
    'models' => [],

    /*
    |--------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------
    |
    | An explicit table-name => Sanitizer class-name map for model-less
    | targets (e.g. a many-to-many pivot table) that have no dedicated
    | Eloquent model. Unlike "models" above, there is no convention-based
    | lookup for a bare table name -- a table has no class name to
    | convention-match against. Configured tables run after "models", in
    | this map's declared order. The --model CLI filter excludes table
    | targets entirely.
    |
    | Example:
    |   'role_user' => \App\Sanitizers\RoleUserSanitizer::class,
    |
    */
    'tables' => [],

    /*
    |--------------------------------------------------------------------
    | Allowed environments
    |--------------------------------------------------------------------
    |
    | The environment guard's allow-list. Overridable so a host app with,
    | e.g., a "dev" environment is not forced to pass --force on every run.
    |
    */
    'environments' => ['local', 'testing'],

    /*
    |--------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------
    |
    | "size" is null by default, meaning the engine sizes chunks
    | automatically; set it (or PII_CHUNK_SIZE) to force a fixed chunk
    | size. "min", "max", and "target_chunks" tune the automatic sizing
    | algorithm. env() appears here, and only here, so a cached config
    | (php artisan config:cache) resolves correctly.
    |
    */
    'chunk' => [
        'size' => env('PII_CHUNK_SIZE'),
        'min' => 500,
        'max' => 5000,
        'target_chunks' => 20,
    ],

    /*
    |--------------------------------------------------------------------
    | Keyed generation
    |--------------------------------------------------------------------
    |
    | The secret key behind the Keyed value-definition, which maps the same
    | input to the same replacement across rows, columns and runs. Read only
    | from the environment (PII_SANITIZER_KEY) and never committed. Either a
    | raw string or "base64:"-prefixed (decoded like APP_KEY), and at least
    | 32 bytes after decoding. It is only required when a sanitizer declares
    | a Keyed field; a run with no Keyed field never reads it. Treat it like
    | the PII it protects: anyone holding the key can recover low-entropy
    | inputs (NRIC, phone numbers) by enumeration.
    |
    */
    'keyed' => [
        'key' => env('PII_SANITIZER_KEY'),
    ],

];
