<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IntegrationToken extends Model
{
    public const PURPOSE_EMIS_SISWA = 'emis_siswa';

    public const PURPOSE_EMIS_GTK = 'emis_gtk';

    public const ABILITIES = [
        'staging:read', 'staging:write',
        'reconcile:read', 'reconcile:run',
        'sync:read', 'sync:trigger', 'sync:cancel',
        'tasks:read',
        'credentials:update',
    ];

    /** Token lama (abilities NULL) hanya boleh membaca. */
    public const LEGACY_ABILITIES = ['staging:read', 'reconcile:read', 'sync:read', 'tasks:read'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'purpose',
        'abilities',
        'token_prefix',
        'token_hash',
        'token_last4',
        'label',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'created_by',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'created_by' => 'string',
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<int,string> */
    public function effectiveAbilities(): array
    {
        return $this->abilities === null ? self::LEGACY_ABILITIES : array_values($this->abilities);
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->effectiveAbilities(), true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isValidFor(?string $purpose = null): bool
    {
        if ($this->isRevoked() || $this->isExpired()) {
            return false;
        }

        if ($purpose !== null && $this->purpose !== $purpose) {
            return false;
        }

        return true;
    }

    public function getMaskedTokenAttribute(): string
    {
        $prefix = $this->token_prefix ?: '****';
        $last4 = $this->token_last4 ?: '****';

        return "pm_int_{$prefix}...{$last4}";
    }
}
