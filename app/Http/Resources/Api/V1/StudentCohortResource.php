<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\StudentAcademicCohort;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentCohortResource extends JsonResource
{
    public const ALLOWED_FIELDS = [
        'id',
        'student_id',
        'academic_year_id',
        'semester',
        'rombel_id',
        'attendance_number',
        'status',
        'created_at',
        'updated_at',
        'rombel',
        'academic_year',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StudentAcademicCohort $cohort */
        $cohort = $this->resource;
        $attributes = array_intersect_key(
            $cohort->attributesToArray(),
            array_flip(self::ALLOWED_FIELDS),
        );

        if ($cohort->relationLoaded('rombel')) {
            $rombel = $cohort->getRelation('rombel');
            $attributes['rombel'] = $rombel instanceof Rombel
                ? (new ClassResource($rombel))->toArray($request)
                : null;
        }

        if ($cohort->relationLoaded('academicYear')) {
            $academicYear = $cohort->getRelation('academicYear');
            $attributes['academic_year'] = $academicYear instanceof AcademicYear
                ? (new AcademicYearResource($academicYear))->toArray($request)
                : null;
        }

        return $attributes;
    }
}
