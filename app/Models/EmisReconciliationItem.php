<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmisReconciliationItem extends Model
{
    public const CLASS_SAME = 'same';

    public const CLASS_DIFFERENT = 'different';

    public const CLASS_PINTU_ONLY = 'pintu_only';

    public const CLASS_EMIS_ONLY = 'emis_only';

    public const CLASS_NEEDS_REVIEW = 'needs_review';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'batch_id',
        'entity_type',
        'entity_id',
        'staging_id',
        'external_reference_id',
        'classification',
        'differences',
        'pintu_snapshot',
        'emis_snapshot',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'batch_id' => 'string',
            'entity_id' => 'string',
            'staging_id' => 'string',
            'differences' => 'array',
            'pintu_snapshot' => 'array',
            'emis_snapshot' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(EmisImportBatch::class, 'batch_id');
    }
}
