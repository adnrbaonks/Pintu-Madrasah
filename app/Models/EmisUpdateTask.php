<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmisUpdateTask extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'entity_type',
        'entity_id',
        'source_type',
        'source_id',
        'changed_fields',
        'status',
        'operator_notes',
        'confirmation_reference',
        'created_by',
        'updated_by',
        'external_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'entity_id' => 'string',
            'source_id' => 'string',
            'changed_fields' => 'array',
            'external_updated_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
            $model->status ??= self::STATUS_PENDING;
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
