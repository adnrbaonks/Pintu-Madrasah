<?php

namespace App\Models;

use App\Services\GradeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGrade extends Model
{
    protected $fillable = [
        'student_id', 'subject_id', 'academic_year_id', 'rombel_id', 'semester',
        'nilai_p1', 'nilai_p2', 'nilai_sts', 'nilai_sas', 'nilai_akhir', 'predikat', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'nilai_p1' => 'float',
            'nilai_p2' => 'float',
            'nilai_sts' => 'float',
            'nilai_sas' => 'float',
            'nilai_akhir' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StudentGrade $grade) {
            $service = app(GradeService::class);
            $calc = $service->calculate([
                'nilai_p1' => $grade->nilai_p1,
                'nilai_p2' => $grade->nilai_p2,
                'nilai_sts' => $grade->nilai_sts,
                'nilai_sas' => $grade->nilai_sas,
            ], $grade->academic_year_id);

            $grade->nilai_akhir = $calc['nilai_akhir'];
            $grade->predikat = $calc['predikat'];
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function rombel(): BelongsTo
    {
        return $this->belongsTo(Rombel::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** Hitung predikat otomatis berdasarkan nilai akhir */
    public function hitungPredikat(): ?string
    {
        return $this->predikat;
    }
}
