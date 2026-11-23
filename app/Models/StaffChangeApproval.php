<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffChangeApproval extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'request_id',
        'stage',
        'actor_user_id',
        'decision',
        'notes',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'request_id' => 'string',
            'actor_user_id' => 'string',
            'decided_at' => 'datetime',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
