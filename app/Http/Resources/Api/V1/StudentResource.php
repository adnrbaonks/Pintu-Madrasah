<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    /**
     * Atribut default non-PII yang aman dikembalikan tanpa scope students:pii.
     */
    public const NON_PII_FIELDS = [
        'id',
        'nisn',
        'nis_local',
        'name',
        'gender',
        'status',
        'entry_year',
        'created_at',
        'updated_at',
        'current_cohort',
        'cohorts',
    ];

    /**
     * Atribut sensitif (PII) yang wajib memerlukan scope students:pii.
     */
    public const PII_FIELDS = [
        'nik',
        'kk_number',
        'birth_place',
        'birth_date',
        'religion',
        'address',
        'phone',
        'father_name',
        'mother_name',
        'guardian_name',
        'previous_school',
        'photo_path',
        'documents',
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
        $attributes = $this->resource->toArray();

        // 1. Periksa izin scope PII pada API client
        $scopes = (array) $request->attributes->get('api_scopes', []);
        $hasPiiScope = in_array('students:pii', $scopes, true);

        // Jika tidak memiliki scope PII, hapus relasi dokumen sensitif jika termuat
        if (! $hasPiiScope && isset($attributes['documents'])) {
            unset($attributes['documents']);
        }

        // 2. Tentukan field yang diizinkan untuk pemanggil ini (deny by default)
        $allowedFields = $hasPiiScope
            ? self::ALLOWED_FIELDS
            : self::NON_PII_FIELDS;

        // 3. Evaluasi parameter ?fields=...
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
