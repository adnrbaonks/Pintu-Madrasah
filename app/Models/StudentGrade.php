<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGrade extends Model
{
    protected $fillable = [
        'student_id', 'subject_id', 'academic_year_id', 'rombel_id', 'semester',
        'nilai_p1', 'nilai_p2', 'nilai_sts', 'nilai_sas', 'predikat', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'nilai_p1'    => 'float',
            'nilai_p2'    => 'float',
            'nilai_sts'   => 'float',
            'nilai_sas'   => 'float',
            'nilai_akhir' => 'float',
        ];
    }

    public function student(): BelongsTo   { return $this->belongsTo(Student::class); }
    public function subject(): BelongsTo   { return $this->belongsTo(Subject::class); }
    public function rombel(): BelongsTo    { return $this->belongsTo(Rombel::class); }
    public function academicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class); }

    /** Hitung predikat otomatis berdasarkan nilai akhir */
    public function hitungPredikat(): string
    {
        return match (true) {
            $this->nilai_akhir >= 90 => 'A',
            $this->nilai_akhir >= 80 => 'B',
            $this->nilai_akhir >= 70 => 'C',
            default                  => 'D',
        };
    }
}
