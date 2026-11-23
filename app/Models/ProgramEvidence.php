<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramEvidence extends Model
{
    use HasUuids;

    protected $table = 'program_evidence';

    protected $fillable = [
        'program_report_id',
        'archive_id',
        'title',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'external_url',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ProgramReport::class, 'program_report_id');
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
