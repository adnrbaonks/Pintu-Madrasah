<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingParticipant extends Model
{
    use HasUuids;

    protected $fillable = [
        'meeting_id',
        'user_id',
        'role_in_meeting',
        'attendance_status',
        'attended_at',
        'absence_reason',
    ];

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
