<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicYearResource extends JsonResource
{
    public const ALLOWED_FIELDS = [
        'id',
        'year',
        'semester',
        'is_active',
        'is_locked',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = array_intersect_key(
            $this->resource->attributesToArray(),
            array_flip(self::ALLOWED_FIELDS),
        );
        $attributes['full_label'] = $this->resource->full_label;

        return $attributes;
    }
}
