<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public const ALLOWED_FIELDS = [
        'id',
        'nik',
        'nuptk',
        'nip',
        'npk',
        'name',
        'full_name_with_titles',
        'first_title',
        'last_title',
        'gender',
        'birth_place',
        'birth_date',
        'phone',
        'email',
        'employment_status',
        'functional_position',
        'highest_education',
        'university_name',
        'study_program',
        'graduation_year',
        'civil_rank',
        'civil_rank_tmt',
        'is_active',
        'created_at',
        'updated_at',
        'position_assignments',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->toArray();

        $fieldsParam = $request->query('fields');
        if (! is_string($fieldsParam) || trim($fieldsParam) === '') {
            return $attributes;
        }

        $requested = array_values(array_intersect(
            self::ALLOWED_FIELDS,
            array_map('trim', explode(',', $fieldsParam)),
        ));

        if (! in_array('id', $requested, true)) {
            array_unshift($requested, 'id');
        }

        return array_intersect_key($attributes, array_flip($requested));
    }
}
