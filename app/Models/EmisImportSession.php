<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmisImportSession extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'entity_type', 'status', 'owner_key', 'total_pages', 'total_count',
        'received_pages', 'received_rows', 'source', 'started_at', 'completed_at',
        'expires_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
