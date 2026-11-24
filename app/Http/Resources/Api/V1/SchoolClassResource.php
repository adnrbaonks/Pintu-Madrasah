<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolClassResource extends JsonResource
{
    public const ALLOWED_FIELDS = [
        'id',
        'grade_level',
        'major_name',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_intersect_key(
            $this->resource->toArray(),
            array_flip(self::ALLOWED_FIELDS),
        );
    }
}
