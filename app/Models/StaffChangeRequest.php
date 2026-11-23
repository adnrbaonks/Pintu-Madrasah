<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StaffChangeRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_TU_REVIEW = 'under_tu_review';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PENDING_HEAD_APPROVAL = 'pending_head_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_PENDING_EXTERNAL_SYNC = 'pending_external_sync';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'staff_id',
        'requester_user_id',
        'status',
        'reason',
        'submitted_at',
        'current_stage',
        'decided_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'staff_id' => 'string',
            'requester_user_id' => 'string',
            'created_by' => 'string',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StaffChangeRequestItem::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(StaffChangeAttachment::class, 'request_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(StaffChangeApproval::class, 'request_id')->orderBy('decided_at');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(StaffChangeHistory::class, 'request_id')->orderBy('applied_at');
    }
}
