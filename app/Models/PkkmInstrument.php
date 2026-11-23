<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PkkmInstrument extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'code',
        'title',
        'period_year',
        'assessment_type',
        'academic_year_id',
        'headmaster_user_id',
        'description',
        'status',
        'overall_score',
        'predicate',
        'finalized_at',
        'finalized_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'overall_score' => 'float',
            'finalized_at' => 'datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function headmaster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'headmaster_user_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PkkmComponent::class)->orderBy('sort_order')->orderBy('code');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(PkkmAssessment::class);
    }

    /**
     * Hitung ulang nilai akhir berbobot & predikat PKKM dari komponen dinamis.
     */
    public function recalculateScore(): void
    {
        $components = $this->components()->with('assessment')->get();
        if ($components->isEmpty()) {
            $this->update(['overall_score' => 0, 'predicate' => null]);

            return;
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;

        foreach ($components as $comp) {
            $weight = max(0.01, (float) $comp->weight);
            $maxScore = max(1, (int) $comp->max_score);
            $assessment = $comp->assessment;

            $rawScore = $assessment
                ? (float) ($assessment->verified_score ?? $assessment->self_score ?? 0)
                : 0.0;

            $normalized = min(100.0, max(0.0, ($rawScore / $maxScore) * 100.0));
            $weightedSum += $normalized * $weight;
            $totalWeight += $weight;
        }

        $finalScore = $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0.0;

        $predicate = match (true) {
            $finalScore >= 91 => 'Amat Baik',
            $finalScore >= 76 => 'Baik',
            $finalScore >= 61 => 'Cukup',
            $finalScore >= 51 => 'Sedang',
            default => 'Kurang',
        };

        $this->update([
            'overall_score' => $finalScore,
            'predicate' => $predicate,
        ]);
    }
}
