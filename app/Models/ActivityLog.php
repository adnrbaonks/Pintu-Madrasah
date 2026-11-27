<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['user_id', 'action', 'model_type', 'model_id', 'before_payload', 'after_payload', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['id' => 'string', 'user_id' => 'string', 'model_id' => 'string', 'before_payload' => 'array', 'after_payload' => 'array', 'created_at' => 'datetime'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?Model $model = null, ?array $before = null, ?array $after = null): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model ? get_class($model) : 'System',
            'model_id' => $model?->getKey(),
            'before_payload' => $before,
            'after_payload' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
