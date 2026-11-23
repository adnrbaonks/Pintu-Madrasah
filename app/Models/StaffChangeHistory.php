<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffChangeHistory extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'request_id',
        'item_id',
        'applied_value',
        'applied_by',
        'applied_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'request_id' => 'string',
            'item_id' => 'string',
            'applied_by' => 'string',
            'applied_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(StaffChangeRequest::class, 'request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StaffChangeRequestItem::class, 'item_id');
    }
}
