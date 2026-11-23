<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramReport extends Model
{
    use HasUuids;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REVISION = 'revision';

    protected $fillable = [
        'program_id',
        'program_activity_id',
        'title',
        'report_date',
        'summary',
        'outcomes',
        'obstacles',
        'realized_budget',
        'achieved_percent',
        'status',
        'submitted_by',
        'verified_by',
        'verified_at',
        'approved_by',
        'approved_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'realized_budget' => 'integer',
            'achieved_percent' => 'integer',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProgramActivity::class, 'program_activity_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ProgramEvidence::class);
    }
}
