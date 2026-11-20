<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StudentDocument extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['student_id', 'document_type', 'gdrive_file_id', 'gdrive_view_url', 'file_size', 'is_verified_ai', 'ai_verification_result', 'status'];

    protected function casts(): array
    {
        return ['id' => 'string', 'student_id' => 'string', 'is_verified_ai' => 'boolean', 'ai_verification_result' => 'array'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function getDocumentTypeLabelAttribute(): string
    {
        return match ($this->document_type) {
            'akta_kelahiran' => 'Akta Kelahiran', 'kartu_keluarga' => 'Kartu Keluarga',
            'ijazah_sd' => 'Ijazah SD/MI', 'foto' => 'Pas Foto', default => ucwords(str_replace('_', ' ', $this->document_type)),
        };
    }
}
