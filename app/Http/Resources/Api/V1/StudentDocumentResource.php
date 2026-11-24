<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StudentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentDocumentResource extends JsonResource
{
    /**
     * Atribut dokumen siswa yang boleh diekspos untuk pemilik scope students:pii.
     * Hasil ekstraksi mentah AI (ai_verification_result) dan ID internal storage
     * (gdrive_file_id, upload_retries) tidak diekspos agar OCR PII mentah tidak bocor.
     */
    public const ALLOWED_FIELDS = [
        'id',
        'student_id',
        'document_type',
        'file_size',
        'is_verified_ai',
        'status',
        'gdrive_view_url',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StudentDocument $document */
        $document = $this->resource;

        $attributes = array_intersect_key(
            $document->attributesToArray(),
            array_flip(self::ALLOWED_FIELDS),
        );
        $attributes['document_type_label'] = $document->document_type_label;

        return $attributes;
    }
}
