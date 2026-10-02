<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PpdbDocument extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'ppdb_registration_id',
        'document_type',
        'document_name',
        'file_path',
        'file_size',
        'mime_type',
        'ai_extracted_data',
        'is_verified',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'file_size' => 'integer',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'ai_extracted_data' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($model) => $model->id ??= (string) Str::uuid());
    }

    public function registration()
    {
        return $this->belongsTo(PpdbRegistration::class, 'ppdb_registration_id');
    }
}
