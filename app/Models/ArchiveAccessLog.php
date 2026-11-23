<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchiveAccessLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'archive_id',
        'archive_version_id',
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'request_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ArchiveVersion::class, 'archive_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
