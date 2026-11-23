<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramActivity extends Model
{
    use HasUuids;

    protected $fillable = [
        'program_id',
        'title',
        'description',
        'target_indicator',
        'pic_user_id',
        'scheduled_start',
        'scheduled_end',
        'budget_allocation',
        'budget_realization',
        'progress_percent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'date:Y-m-d',
            'scheduled_end' => 'date:Y-m-d',
            'budget_allocation' => 'integer',
            'budget_realization' => 'integer',
            'progress_percent' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ProgramReport::class);
    }
}
