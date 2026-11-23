<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StaffChangeRequestItem extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'request_id',
        'field',
        'old_value',
        'proposed_value',
        'owner_system',
        'change_policy',
        'apply_status',
        'apply_error',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'request_id' => 'string',
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

    public function histories(): HasMany
    {
        return $this->hasMany(StaffChangeHistory::class, 'item_id');
    }
}
