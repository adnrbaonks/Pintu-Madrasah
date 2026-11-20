<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DocumentVerification extends Model
{
    public const TYPE_BIODATA = 'biodata';

    public const TYPE_SURAT_KELUAR = 'surat_keluar';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'token', 'document_type', 'verifiable_type', 'verifiable_id',
        'snapshot', 'issued_by',
    ];

    protected function casts(): array
    {
        return ['id' => 'string', 'snapshot' => 'array'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            $m->token ??= Str::random(32);
        });
    }

    public function verifiable()
    {
        return $this->morphTo();
    }

    public function verifyUrl(): string
    {
        return rtrim(config('app.url'), '/').'/v/'.$this->token;
    }

    /**
     * Terbitkan (atau segarkan) bukti verifikasi per dokumen.
     * Satu dokumen = satu token stabil agar QR cetakan lama tetap valid.
     */
    public static function issue(string $type, ?Model $model, array $snapshot): self
    {
        $existing = $model
            ? static::where('document_type', $type)
                ->where('verifiable_type', $model::class)
                ->where('verifiable_id', $model->getKey())
                ->first()
            : null;

        if ($existing) {
            $existing->update([
                'snapshot' => $snapshot,
                'issued_by' => auth()->id(),
            ]);

            return $existing->fresh();
        }

        return static::create([
            'document_type' => $type,
            'verifiable_type' => $model ? $model::class : null,
            'verifiable_id' => $model?->getKey(),
            'snapshot' => $snapshot,
            'issued_by' => auth()->id(),
        ]);
    }
}
