<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'name',
        'username',
        'password',
        'role',
        'staff_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'id'        => 'string',
            'staff_id'  => 'string',
            'is_active' => 'boolean',
            'password'  => 'hashed',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    // ── Relationships ─────────────────────────────────────────────────

    public function staff(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function activityLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    public function isAdmin(): bool   { return $this->role === 'admin'; }
    public function isStaf(): bool    { return $this->role === 'staf'; }
    public function isGuru(): bool    { return $this->role === 'guru'; }
}
