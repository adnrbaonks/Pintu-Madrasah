<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchiveVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'archive_id',
        'version_number',
        'storage_disk',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'checksum_sha256',
        'google_drive_id',
        'google_drive_link',
        'change_notes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
