<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'code',
        'title',
        'description',
        'academic_year_id',
        'organization_unit_id',
        'pic_user_id',
        'start_date',
        'end_date',
        'budget_amount',
        'status',
        'progress_percent',
        'approved_by',
        'approved_at',
        'approval_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'budget_amount' => 'integer',
            'progress_percent' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProgramActivity::class)->orderBy('scheduled_start');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ProgramReport::class)->orderByDesc('report_date');
    }

    public function recalculateProgress(): void
    {
        $activities = $this->activities()->get();
        if ($activities->isEmpty()) {
            return;
        }

        $avg = (int) round((float) $activities->avg('progress_percent'));
        $this->update(['progress_percent' => min(100, max(0, $avg))]);
    }
}
