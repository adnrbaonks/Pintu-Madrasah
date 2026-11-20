<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\GradingFormula;

class GradeService
{
    /**
     * Hitung nilai akhir dan predikat dari komponen nilai.
     *
     * @param array{
     *     nilai_p1?: float|int|null,
     *     nilai_p2?: float|int|null,
     *     nilai_sts?: float|int|null,
     *     nilai_sas?: float|int|null
     * } $components
     * @return array{
     *     nilai_akhir: float|null,
     *     predikat: string|null
     * }
     */
    public function calculate(array $components, AcademicYear|string|null $academicYearOrId = null): array
    {
        $formula = $this->resolveFormula($academicYearOrId);

        $p1 = $components['nilai_p1'] ?? null;
        $p2 = $components['nilai_p2'] ?? null;
        $sts = $components['nilai_sts'] ?? null;
        $sas = $components['nilai_sas'] ?? null;

        // Jika semua komponen kosong, nilai akhir kosong
        if ($p1 === null && $p2 === null && $sts === null && $sas === null) {
            return [
                'nilai_akhir' => null,
                'predikat' => null,
            ];
        }

        $items = [
            'p1' => ['val' => $p1, 'weight' => (float) $formula->weight_p1],
            'p2' => ['val' => $p2, 'weight' => (float) $formula->weight_p2],
            'sts' => ['val' => $sts, 'weight' => (float) $formula->weight_sts],
            'sas' => ['val' => $sas, 'weight' => (float) $formula->weight_sas],
        ];

        $totalScore = 0.0;
        $activeWeight = 0.0;

        foreach ($items as $item) {
            if ($item['val'] !== null && $item['weight'] > 0) {
                $totalScore += ((float) $item['val']) * ($item['weight'] / 100.0);
                $activeWeight += $item['weight'];
            }
        }

        if ($activeWeight <= 0) {
            return [
                'nilai_akhir' => null,
                'predikat' => null,
            ];
        }

        // Normalisasi ke skala 100 jika sebagian komponen belum diisi
        $finalScore = round(($totalScore / ($activeWeight / 100.0)), 2);
        $finalScore = max(0.0, min(100.0, $finalScore));

        return [
            'nilai_akhir' => $finalScore,
            'predikat' => $this->predikat($finalScore, $formula),
        ];
    }

    /**
     * Tentukan predikat (A, B, C, D) berdasarkan ambang batas formula.
     */
    public function predikat(float $score, ?GradingFormula $formula = null): string
    {
        $formula ??= GradingFormula::defaultFormula();

        $threshA = (float) $formula->threshold_a;
        $threshB = (float) $formula->threshold_b;
        $threshC = (float) $formula->threshold_c;

        return match (true) {
            $score >= $threshA => 'A',
            $score >= $threshB => 'B',
            $score >= $threshC => 'C',
            default => 'D',
        };
    }

    /**
     * Ambil formula aktif untuk tahun ajaran tertentu, fallback ke default.
     */
    public function resolveFormula(AcademicYear|string|null $academicYearOrId = null): GradingFormula
    {
        $id = $academicYearOrId instanceof AcademicYear ? $academicYearOrId->id : $academicYearOrId;

        if ($id) {
            $formula = GradingFormula::where('academic_year_id', $id)->first();
            if ($formula) {
                return $formula;
            }
        }

        return GradingFormula::defaultFormula($id);
    }
}
