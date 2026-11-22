<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OutboxEvent extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'status',
        'available_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'aggregate_id' => 'string',
            'payload' => 'array',
            'available_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }
}
