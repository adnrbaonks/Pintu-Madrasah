<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArchiveCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'description',
        'retention_days',
        'default_visibility',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class);
    }
}
