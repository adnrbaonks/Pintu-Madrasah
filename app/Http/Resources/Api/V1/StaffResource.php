<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PositionAssignment;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * Atribut default non-PII yang aman dikembalikan tanpa scope staff:pii.
     * Seluruh nomor identitas kepegawaian/kependudukan GTK (NIK, NIP, NUPTK, NPK)
     * diklasifikasikan sebagai PII dan wajib memerlukan scope staff:pii.
     */
    public const NON_PII_FIELDS = [
        'id',
        'name',
        'full_name_with_titles',
        'first_title',
        'last_title',
        'gender',
        'employment_status',
        'functional_position',
        'is_active',
        'created_at',
        'updated_at',
        'position_assignments',
    ];

    /**
     * Atribut sensitif (PII) yang wajib memerlukan scope staff:pii.
     */
    public const PII_FIELDS = [
        'nik',
        'nuptk',
        'nip',
        'npk',
        'birth_place',
        'birth_date',
        'phone',
        'email',
        'highest_education',
        'university_name',
        'study_program',
        'graduation_year',
        'civil_rank',
        'civil_rank_tmt',
    ];

    public const ALLOWED_FIELDS = [
        ...self::NON_PII_FIELDS,
        ...self::PII_FIELDS,
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Staff $staff */
        $staff = $this->resource;
        $attributes = $staff->attributesToArray();
        $attributes['name'] = $staff->name;

        // 1. Periksa izin scope PII pada API client
        $scopes = (array) $request->attributes->get('api_scopes', []);
        $hasPiiScope = in_array('staff:pii', $scopes, true);

        // 2. Transformasi relasi penugasan jabatan melalui Resource eksplisit
        if ($staff->relationLoaded('positionAssignments')) {
            $attributes['position_assignments'] = collect($staff->getRelation('positionAssignments'))
                ->filter(fn ($assignment): bool => $assignment instanceof PositionAssignment)
                ->map(fn (PositionAssignment $assignment): array => (new PositionAssignmentResource($assignment))->toArray($request))
                ->values()
                ->all();
        }

        // 3. Tentukan field yang diizinkan untuk pemanggil ini (deny by default)
        $allowedFields = $hasPiiScope
            ? self::ALLOWED_FIELDS
            : self::NON_PII_FIELDS;

        // 4. Evaluasi parameter ?fields=...
        $fieldsParam = $request->query('fields');
        if (is_string($fieldsParam) && trim($fieldsParam) !== '') {
            $requested = array_map('trim', explode(',', $fieldsParam));
            // Abaikan/tolak field yang tidak diizinkan sesuai scope
            $selectedFields = array_values(array_intersect($allowedFields, $requested));

            if (! in_array('id', $selectedFields, true)) {
                array_unshift($selectedFields, 'id');
            }
        } else {
            // Tanpa parameter fields khusus, kembalikan hanya atribut non-PII default
            $selectedFields = self::NON_PII_FIELDS;
        }

        return array_intersect_key($attributes, array_flip($selectedFields));
    }
}
