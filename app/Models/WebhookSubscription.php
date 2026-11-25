<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Secret HMAC tidak boleh ikut terserialisasi ke JSON / Inertia props.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret',
    ];

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
            if (($model->getRawOriginal('secret') ?? $model->attributes['secret'] ?? null) === null) {
                $model->secret = 'whsec_'.Str::random(48);
            }
        });
    }

    /**
     * Enkripsi secret saat disimpan (at-rest) dengan kompatibilitas baca mundur untuk secret lama.
     */
    protected function secret(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }

                $state = Setting::encryptionState($value);
                if ($state === 'encrypted') {
                    return decrypt($value);
                }

                if ($state === 'invalid_ciphertext') {
                    Log::warning('WebhookSubscription secret uses an unavailable APP_KEY; preserving ciphertext.', [
                        'id' => $this->id,
                    ]);

                    return null;
                }

                // Legacy plaintext secret: migrasikan ke ciphertext secara idempoten bila record sudah tersimpan.
                if ($this->exists && $this->id) {
                    try {
                        DB::table($this->getTable())
                            ->where('id', $this->id)
                            ->update(['secret' => encrypt($value)]);
                    } catch (\Throwable) {
                        // Abaikan jika koneksi sedang read-only.
                    }
                }

                return $value;
            },
            set: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }

                if (Setting::encryptionState($value) !== 'plaintext') {
                    return $value;
                }

                return encrypt($value);
            },
        );
    }

    /**
     * Migrasi batch secret webhook lama yang masih tersimpan dalam bentuk plaintext.
     */
    public static function migratePlaintextSecrets(): int
    {
        $migrated = 0;
        $rows = DB::table('webhook_subscriptions')->select(['id', 'secret'])->get();

        foreach ($rows as $row) {
            if (! is_string($row->secret) || $row->secret === '') {
                continue;
            }

            try {
                $state = Setting::encryptionState($row->secret);
                if ($state === 'plaintext') {
                    DB::table('webhook_subscriptions')
                        ->where('id', $row->id)
                        ->update(['secret' => encrypt($row->secret)]);
                    $migrated++;
                } elseif ($state === 'invalid_ciphertext') {
                    Log::warning('WebhookSubscription secret uses an unavailable APP_KEY; preserving ciphertext.', [
                        'id' => $row->id,
                    ]);
                }
            } catch (DecryptException) {
                // Jangan timpa ciphertext yang tidak dapat didekripsi dengan APP_KEY aktif.
            }
        }

        return $migrated;
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
