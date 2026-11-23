<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public const ALLOWED_FIELDS = [
        'id',
        'nisn',
        'nis_local',
        'nik',
        'family_card_number',
        'name',
        'gender',
        'birth_place',
        'birth_date',
        'religion',
        'status',
        'address',
        'phone',
        'father_name',
        'mother_name',
        'guardian_name',
        'entry_year',
        'previous_school',
        'photo_path',
        'created_at',
        'updated_at',
        'current_cohort',
        'cohorts',
        'documents',
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
