<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmisImportBatch extends Model
{
    public const PROCESS_INITIAL_IMPORT = 'initial_import';

    public const PROCESS_RECONCILIATION = 'reconciliation';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'entity_type',
        'source_system',
        'process_type',
        'process_identifier',
        'allow_overwrite',
        'overwrite_confirmed',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_existing_count',
        'matched_identical_count',
        'difference_count',
        'pintu_only_count',
        'emis_only_count',
        'needs_review_count',
        'imported_by',
        'imported_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'allow_overwrite' => 'boolean',
            'overwrite_confirmed' => 'boolean',
            'total_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_existing_count' => 'integer',
            'matched_identical_count' => 'integer',
            'difference_count' => 'integer',
            'pintu_only_count' => 'integer',
            'emis_only_count' => 'integer',
            'needs_review_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
            $model->imported_at ??= now();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmisReconciliationItem::class, 'batch_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
