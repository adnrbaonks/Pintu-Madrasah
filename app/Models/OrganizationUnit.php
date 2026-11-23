<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OrganizationUnit extends Model
{
    protected $table = 'org_units';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'type',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'parent_id' => 'string',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'org_unit_id')->orderBy('level')->orderBy('name');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class, 'org_unit_id');
    }

    public function scopeEffectiveOn(Builder $query, CarbonInterface|string|null $date = null): Builder
    {
        $target = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($target) {
                $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $target);
            })
            ->where(function (Builder $q) use ($target) {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $target);
            });
    }
}
