<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PositionAssignment extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'staff_id',
        'position_id',
        'org_unit_id',
        'sk_number',
        'valid_from',
        'valid_until',
        'is_primary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'staff_id' => 'string',
            'position_id' => 'string',
            'org_unit_id' => 'string',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_primary' => 'boolean',
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

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'org_unit_id');
    }

    public function scopeEffectiveOn(Builder $query, CarbonInterface|string|null $date = null): Builder
    {
        $target = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $target)
            ->where(function (Builder $q) use ($target) {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $target);
            });
    }
}
