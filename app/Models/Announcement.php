<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'content',
        'target_scope',
        'target_roles',
        'priority',
        'is_pinned',
        'publish_date',
        'expires_at',
        'author_user_id',
        'archive_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'is_pinned' => 'boolean',
            'is_active' => 'boolean',
            'publish_date' => 'date:Y-m-d',
            'expires_at' => 'date:Y-m-d',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }
}
