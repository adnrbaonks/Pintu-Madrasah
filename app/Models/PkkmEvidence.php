<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkkmEvidence extends Model
{
    use HasUuids;

    protected $table = 'pkkm_evidence';

    protected $fillable = [
        'pkkm_component_id',
        'archive_id',
        'title',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'external_url',
        'notes',
        'verification_status',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PkkmComponent::class, 'pkkm_component_id');
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
