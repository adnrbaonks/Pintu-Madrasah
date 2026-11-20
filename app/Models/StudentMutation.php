<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMutation extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'emis_student_id',
        'nisn',
        'name',
        'type',
        'mutation_date',
        'reason',
        'origin_school',
        'destination_school',
        'status',
        'document_path',
        'gdrive_file_id',
        'gdrive_view_url',
        'document_status',
        'upload_retries',
        'raw_data',
    ];

    protected $casts = [
        'mutation_date' => 'date',
        'upload_retries' => 'integer',
        'raw_data' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
