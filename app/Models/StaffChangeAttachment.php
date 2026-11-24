<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffChangeAttachment extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'request_id',
        'item_id',
        'path',
        'original_name',
        'mime',
        'size',
        'checksum',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'request_id' => 'string',
            'item_id' => 'string',
            'uploaded_by' => 'string',
            'size' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(StaffChangeRequest::class, 'request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StaffChangeRequestItem::class, 'item_id');
    }
}
