<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Position extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'org_unit_id',
        'code',
        'name',
        'level',
        'is_structural',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'org_unit_id' => 'string',
            'level' => 'integer',
            'is_structural' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'org_unit_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class, 'position_id');
    }

    public function scopeEffectiveOn(Builder $query, CarbonInterface|string|null $date = null): Builder
    {
        $target = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query
            ->where(function (Builder $q) use ($target) {
                $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $target);
            })
            ->where(function (Builder $q) use ($target) {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $target);
            });
    }
}
