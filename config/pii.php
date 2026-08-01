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
    | Protected columns
    |--------------------------------------------------------------------
    |
    | Additional audit/temporal columns never written unless explicitly
    | declared in a Sanitizer's fields(). Merged with each model's own
    | created_at/updated_at/deleted_at columns.
    |
    */
    'protected_columns' => ['created_at', 'updated_at', 'deleted_at'],

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

];
