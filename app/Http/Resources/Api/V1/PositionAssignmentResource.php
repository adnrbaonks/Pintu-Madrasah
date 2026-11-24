<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionAssignmentResource extends JsonResource
{
    public const ALLOWED_FIELDS = [
        'id',
        'staff_id',
        'position_id',
        'org_unit_id',
        'sk_number',
        'valid_from',
        'valid_until',
        'is_primary',
        'status',
        'created_at',
        'updated_at',
    ];

    public const POSITION_FIELDS = [
        'id',
        'org_unit_id',
        'code',
        'name',
        'level',
        'is_structural',
        'valid_from',
        'valid_until',
    ];

    public const UNIT_FIELDS = [
        'id',
        'parent_id',
        'code',
        'name',
        'type',
        'is_active',
        'valid_from',
        'valid_until',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PositionAssignment $assignment */
        $assignment = $this->resource;
        $attributes = array_intersect_key(
            $assignment->attributesToArray(),
            array_flip(self::ALLOWED_FIELDS),
        );

        if ($assignment->relationLoaded('position')) {
            $position = $assignment->getRelation('position');
            $attributes['position'] = $position instanceof Position
                ? array_intersect_key($position->attributesToArray(), array_flip(self::POSITION_FIELDS))
                : null;
        }

        if ($assignment->relationLoaded('unit')) {
            $unit = $assignment->getRelation('unit');
            $attributes['unit'] = $unit instanceof OrganizationUnit
                ? array_intersect_key($unit->attributesToArray(), array_flip(self::UNIT_FIELDS))
                : null;
        }

        return $attributes;
    }
}
