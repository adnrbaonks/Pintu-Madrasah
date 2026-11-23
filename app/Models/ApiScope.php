<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ApiScope extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'group',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'is_active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(ApiClient::class, 'api_client_scope')->withTimestamps();
    }
}
