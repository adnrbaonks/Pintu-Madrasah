<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingMinuteRevision extends Model
{
    use HasUuids;

    protected $fillable = [
        'meeting_minute_id',
        'version_number',
        'previous_discussion_summary',
        'previous_decisions',
        'previous_archive_id',
        'previous_approved_by',
        'previous_approved_at',
        'revision_notes',
        'revised_by',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'previous_approved_at' => 'datetime',
        ];
    }

    public function minute(): BelongsTo
    {
        return $this->belongsTo(MeetingMinute::class, 'meeting_minute_id');
    }

    public function reviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
