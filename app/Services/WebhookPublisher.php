<?php

namespace App\Services;

use App\Models\WebhookSubscription;

class WebhookPublisher
{
    /**
     * Daftar kunci identitas sensitif yang dihapus ketika `include_pii = false`.
     *
     * @var array<int, string>
     */
    public const IDENTITY_PII_KEYS = [
        'nik',
        'nisn',
        'nip',
        'nuptk',
        'npk',
        'kk_number',
        'family_card_number',
        'emis_student_id',
        'emis_id',
    ];

    /**
     * Daftar kunci data pribadi sensitif yang dihapus ketika `include_pii = false`.
     *
     * @var array<int, string>
     */
    public const PERSONAL_PII_KEYS = [
        'gender',
        'birth_place',
        'birth_date',
        'religion',
        'address',
        'phone',
        'email',
        'father_name',
        'mother_name',
        'guardian_name',
        'father_income',
        'mother_income',
        'guardian_income',
        'previous_school',
        'photo_path',
        'civil_rank',
        'civil_rank_tmt',
        'highest_education',
        'university_name',
        'study_program',
        'graduation_year',
    ];

    /**
     * Daftar gabungan seluruh kunci PII sensitif yang dihapus secara default bila `include_pii = false`.
     *
     * @var array<int, string>
     */
    public const PII_KEYS = [
        ...self::IDENTITY_PII_KEYS,
        ...self::PERSONAL_PII_KEYS,
    ];

    /**
     * Daftar header respons sensitif yang wajib diredaksi sebelum disimpan ke log DB.
     *
     * @var array<int, string>
     */
    private const REDACTED_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-integration-token',
    ];

    /**
     * Bentuk payload standar webhook v1.
     *
     * @param  array<string, mixed>  $data
     * @return array{event: string, event_id: string, occurred_at: string, version: int, data: array<string, mixed>}
     */
    public function buildPayload(
        string $event,
        string $eventId,
        array $data,
        ?WebhookSubscription $subscription = null,
        ?string $occurredAt = null,
    ): array {
        $allowedPii = $this->canIncludePii($event, $subscription);

        $filteredData = $allowedPii
            ? $data
            : $this->stripPii($data);

        return [
            'event' => $event,
            'event_id' => $eventId,
            'occurred_at' => $occurredAt ?? now()->toIso8601String(),
            'version' => 1,
            'data' => $filteredData,
        ];
    }

    /**
     * Evaluasi apakah subscription berhak menerima PII untuk event tertentu.
     * Bila subscription tertaut ke API Client (`api_client_id`), client tersebut wajib aktif
     * dan memiliki scope PII yang relevan (`students:pii` / `staff:pii`).
     */
    public function canIncludePii(string $event, ?WebhookSubscription $subscription): bool
    {
        if ($subscription === null || ! $subscription->include_pii) {
            return false;
        }

        if ($subscription->api_client_id === null) {
            return true;
        }

        $client = $subscription->relationLoaded('apiClient')
            ? $subscription->apiClient
            : $subscription->apiClient()->first();

        if (! $client || $client->isRevoked() || $client->isExpired()) {
            return false;
        }

        if (str_starts_with($event, 'student.')) {
            return $client->hasScope('students:pii');
        }

        if (str_starts_with($event, 'staff.')) {
            return $client->hasScope('staff:pii');
        }

        return $client->hasScope('students:pii') || $client->hasScope('staff:pii');
    }

    /**
     * Hapus atribut PII secara rekursif dari payload (termasuk nested object dan daftar `changed_fields`)
     * bila langganan tidak berhak menerima PII.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function stripPii(array $data): array
    {
        $piiLookup = array_fill_keys(self::PII_KEYS, true);
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && isset($piiLookup[$key])) {
                continue;
            }

            if ($key === 'changed_fields' && is_array($value)) {
                $sanitized[$key] = array_values(array_filter(
                    $value,
                    fn (mixed $field): bool => ! is_string($field) || ! isset($piiLookup[$field])
                ));

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->stripPii($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Signature v1 (legacy / kompatibilitas): HMAC-SHA256 atas raw JSON body.
     */
    public function signatureV1(string $rawBody, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * Signature v2 (anti-replay): HMAC-SHA256 atas "{timestamp}.{rawBody}".
     */
    public function signatureV2(int $timestamp, string $rawBody, string $secret): string
    {
        return 'v2='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    public function verifySignatureV2(
        int $timestamp,
        string $rawBody,
        string $secret,
        string $providedSignature,
        int $toleranceSeconds = 300,
    ): bool {
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = $this->signatureV2($timestamp, $rawBody, $secret);

        return hash_equals($expected, $providedSignature);
    }

    /**
     * Redaksi header respons HTTP sebelum disimpan ke `webhook_deliveries.response_headers`.
     *
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, string>
     */
    public function redactHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $name => $values) {
            $lower = strtolower((string) $name);
            if (in_array($lower, self::REDACTED_HEADERS, true)) {
                $sanitized[$name] = '[REDACTED]';

                continue;
            }

            $valueString = is_array($values) ? implode(', ', $values) : (string) $values;
            $sanitized[$name] = mb_substr($valueString, 0, 255);
        }

        return $sanitized;
    }
}
