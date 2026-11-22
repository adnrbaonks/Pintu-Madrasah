<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffDocument extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'staff_id',
        'document_type',
        'title',
        'file_path',
        'mime_type',
        'file_size',
        'gdrive_file_id',
        'gdrive_view_url',
        'status',
        'upload_retries',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'staff_id' => 'string',
            'file_size' => 'integer',
            'upload_retries' => 'integer',
            'uploaded_by' => 'string',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function getDocumentTypeLabelAttribute(): string
    {
        return match ($this->document_type) {
            'sk_pengangkatan' => 'SK Pengangkatan',
            'ijazah' => 'Ijazah Pendidikan',
            'sertifikat_pendidik' => 'Sertifikat Pendidik',
            'ktp' => 'KTP',
            'kk' => 'Kartu Keluarga',
            default => ucwords(str_replace('_', ' ', $this->document_type)),
        };
    }
}
