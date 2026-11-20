<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GradingFormula extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'academic_year_id',
        'weight_p1',
        'weight_p2',
        'weight_sts',
        'weight_sas',
        'threshold_a',
        'threshold_b',
        'threshold_c',
        'formula_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'weight_p1' => 'float',
            'weight_p2' => 'float',
            'weight_sts' => 'float',
            'weight_sas' => 'float',
            'threshold_a' => 'float',
            'threshold_b' => 'float',
            'threshold_c' => 'float',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Mengembalikan formula default jika tahun ajaran belum punya formulanya.
     */
    public static function defaultFormula(?string $academicYearId = null): self
    {
        return new self([
            'academic_year_id' => $academicYearId,
            'weight_p1' => 20.00,
            'weight_p2' => 20.00,
            'weight_sts' => 30.00,
            'weight_sas' => 30.00,
            'threshold_a' => 90.00,
            'threshold_b' => 80.00,
            'threshold_c' => 70.00,
            'formula_type' => 'weighted_average',
        ]);
    }
}
