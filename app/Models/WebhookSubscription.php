<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookSubscription extends Model
{
    public const EVENTS = [
        'student.created'  => 'Siswa baru ditambahkan (antrean RFID SIPSMAD)',
        'student.updated'  => 'Biodata siswa diperbarui',
        'student.mutated'  => 'Siswa mutasi keluar / lulus / dikeluarkan (nonaktifkan presensi)',
        'student.promoted' => 'Kenaikan kelas massal (update pemetaan rombel)',
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'api_client_id', 'event', 'target_url', 'secret', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            $m->secret ??= 'whsec_' . Str::random(48);
        });
    }

    public function apiClient()
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class)->latest();
    }
}
