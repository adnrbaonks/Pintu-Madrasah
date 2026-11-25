<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public const SCOPES = [
        'students:read' => 'Baca data dasar siswa (tanpa identitas nasional/PII)',
        'students:identifiers' => 'Baca kode identitas nasional siswa (NISN)',
        'students:pii' => 'Baca data sensitif (PII) siswa (termasuk NISN, NIK, KK, alamat, dokumen)',
        'staff:read' => 'Baca data dasar guru & staf',
        'staff:pii' => 'Baca data sensitif (PII) guru & staf',
        'classes:read' => 'Baca data rombel & kelas',
        'academic-years:read' => 'Baca tahun ajaran aktif',
    ];

    protected $fillable = [
        'name',
        'api_key',
        'key_hash',
        'key_prefix',
        'key_last4',
        'scopes',
        'is_active',
        'last_used_at',
        'last_rotated_at',
        'expires_at',
        'revoked_at',
        'notes',
    ];

    protected $hidden = [
        'api_key',
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'scopes' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'last_rotated_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();

            // Jika dibuat dengan plaintext api_key tetapi belum punya key_hash, otomatis hash
            if ($model->api_key !== null && $model->key_hash === null) {
                $raw = (string) $model->api_key;
                $model->key_hash = hash('sha256', $raw);
                $model->key_prefix ??= str_starts_with($raw, 'pm_live_')
                    ? substr($raw, 8, 8)
                    : substr($raw, 0, 8);
                $model->key_last4 ??= substr($raw, -4);
            }
        });
    }

    public function scopeModels(): BelongsToMany
    {
        return $this->belongsToMany(ApiScope::class, 'api_client_scope')->withTimestamps();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return ! $this->is_active || $this->revoked_at !== null;
    }

    /**
     * Sembunyikan sebagian key di daftar: pm_live_<prefix>...<last4>
     */
    public function getMaskedKeyAttribute(): string
    {
        if ($this->key_prefix && $this->key_last4) {
            return "pm_live_{$this->key_prefix}...{$this->key_last4}";
        }

        $key = (string) $this->api_key;
        if (strlen($key) <= 15) {
            return 'pm_live_****...****';
        }

        return substr($key, 0, 11).'...'.substr($key, -4);
    }
}
