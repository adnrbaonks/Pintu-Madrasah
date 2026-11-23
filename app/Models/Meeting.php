<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'meeting_number',
        'title',
        'meeting_type',
        'scheduled_at',
        'ended_at',
        'location',
        'is_online',
        'online_meeting_url',
        'organization_unit_id',
        'leader_user_id',
        'notetaker_user_id',
        'agenda',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_online' => 'boolean',
        ];
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    public function notetaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notetaker_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function minutes(): HasOne
    {
        return $this->hasOne(MeetingMinute::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(MeetingAction::class);
    }

    public function calendarEvent(): HasOne
    {
        return $this->hasOne(CalendarEvent::class);
    }
}
