<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmisImportSession extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_FINALIZING = 'finalizing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'entity_type', 'status', 'owner_key', 'total_pages', 'total_count',
        'received_pages', 'received_rows', 'saved_rows', 'source', 'started_at',
        'finalizing_at', 'completed_at', 'failed_at', 'expires_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'total_pages' => 'integer',
            'total_count' => 'integer',
            'received_pages' => 'integer',
            'received_rows' => 'integer',
            'saved_rows' => 'integer',
            'started_at' => 'datetime',
            'finalizing_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EmisImportSessionPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(EmisImportSessionPage::class, 'session_id');
    }
}
