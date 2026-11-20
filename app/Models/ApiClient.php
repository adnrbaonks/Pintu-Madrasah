<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public const SCOPES = [
        'students:read' => 'Baca data siswa',
        'staff:read' => 'Baca data guru & staf',
        'classes:read' => 'Baca data rombel & kelas',
        'academic-years:read' => 'Baca tahun ajaran aktif',
    ];

    protected $fillable = [
        'name', 'api_key', 'scopes', 'is_active', 'last_used_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'scopes' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            $m->api_key ??= 'pm_live_'.Str::random(48);
        });
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** Sembunyikan sebagian key di daftar: pm_live_xxxx...zzzz */
    public function getMaskedKeyAttribute(): string
    {
        $key = $this->api_key;
        if (strlen($key) <= 15) {
            return '***';
        }

        return substr($key, 0, 11).'...'.substr($key, -4);
    }
}
