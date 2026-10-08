<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal, minimal Eloquent model used only so a `pii.tables` (model-less)
 * target can flow through every existing `Model $row`-typed contract
 * (ReplacementGenerator, ValueGenerator, closures, SchemaGuard,
 * DataQualityGuard, UniqueColumnInspector, ChunkSizer) completely unchanged
 * — see docs/design/engine-hardening/spec §R7 Model-less table target. Not
 * intended for use outside the package.
 */
final class TableRow extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;

    public static function forTable(string $table): self
    {
        $row = new self;
        $row->setTable($table);

        return $row;
    }
}
