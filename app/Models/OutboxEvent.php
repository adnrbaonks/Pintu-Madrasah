<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'status',
        'available_at',
        'reserved_at',
        'lease_until',
        'leased_by',
        'attempts',
        'max_attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'aggregate_id' => 'string',
            'payload' => 'array',
            'available_at' => 'datetime',
            'reserved_at' => 'datetime',
            'lease_until' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function maxAttempts(): int
    {
        return max(1, (int) ($this->max_attempts ?: 5));
    }

    public function isLeaseExpired(): bool
    {
        return $this->lease_until !== null && $this->lease_until->isPast();
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }
}
