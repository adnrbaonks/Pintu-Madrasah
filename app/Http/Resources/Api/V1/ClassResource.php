<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassResource extends JsonResource
{
    public const ALLOWED_FIELDS = [
        'id',
        'academic_year_id',
        'class_id',
        'name',
        'homeroom_teacher_id',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Rombel $rombel */
        $rombel = $this->resource;
        $attributes = array_intersect_key(
            $rombel->attributesToArray(),
            array_flip(self::ALLOWED_FIELDS),
        );

        if ($rombel->relationLoaded('schoolClass')) {
            $schoolClass = $rombel->getRelation('schoolClass');
            $attributes['school_class'] = $schoolClass instanceof SchoolClass
                ? (new SchoolClassResource($schoolClass))->toArray($request)
                : null;
        }

        if ($rombel->relationLoaded('homeroomTeacher')) {
            $teacher = $rombel->getRelation('homeroomTeacher');
            $attributes['homeroom_teacher'] = $teacher instanceof Staff
                ? $this->transformHomeroomTeacher($teacher, $request)
                : null;
        }

        if ($rombel->relationLoaded('academicYear')) {
            $academicYear = $rombel->getRelation('academicYear');
            $attributes['academic_year'] = $academicYear instanceof AcademicYear
                ? (new AcademicYearResource($academicYear))->toArray($request)
                : null;
        }

        return $attributes;
    }

    /**
     * Transformasi relasi wali kelas secara aman tanpa mewarisi query ?fields= milik resource induk
     * dan tanpa membocorkan atribut PII GTK (nik, nip, nuptk, npk, phone, email, birth_date, tpg_status).
     *
     * @return array<string, mixed>
     */
    private function transformHomeroomTeacher(Staff $teacher, Request $request): array
    {
        $subRequest = clone $request;
        $subRequest->query->remove('fields');

        return (new StaffResource($teacher))->toArray($subRequest);
    }
}
