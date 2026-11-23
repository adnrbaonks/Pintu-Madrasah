<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebhookSubscription extends Model
{
    public const EVENTS = [
        'student.created' => 'Siswa baru ditambahkan (antrean RFID SIPSMAD)',
        'student.updated' => 'Biodata siswa diperbarui',
        'student.mutated' => 'Siswa mutasi keluar / lulus / dikeluarkan (nonaktifkan presensi)',
        'student.promoted' => 'Kenaikan kelas massal (update pemetaan rombel)',
        'staff.created' => 'Data guru / tenaga kependidikan baru ditambahkan',
        'staff.updated' => 'Profil / biodata GTK diperbarui',
        'staff.deactivated' => 'Status GTK dinonaktifkan',
        'staff.position.assigned' => 'Penugasan jabatan / SK struktural GTK ditetapkan',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'api_client_id',
        'event',
        'target_url',
        'description',
        'secret',
        'is_active',
        'include_pii',
        'max_attempts',
        'last_triggered_at',
        'last_status',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'is_active' => 'boolean',
            'include_pii' => 'boolean',
            'max_attempts' => 'integer',
            'last_triggered_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
            $model->secret ??= 'whsec_'.Str::random(48);
        });
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest();
    }
}
