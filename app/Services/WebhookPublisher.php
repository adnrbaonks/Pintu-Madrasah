<?php

namespace App\Services;

use App\Models\WebhookSubscription;

class WebhookPublisher
{
    /**
     * Daftar kunci PII sensitif yang dihapus secara default bila `include_pii = false`.
     *
     * @var array<int, string>
     */
    public const PII_KEYS = [
        'nik',
        'family_card_number',
        'father_income',
        'mother_income',
        'guardian_income',
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
        $filteredData = ($subscription === null || ! $subscription->include_pii)
            ? $this->stripPii($data)
            : $data;

        return [
            'event' => $event,
            'event_id' => $eventId,
            'occurred_at' => $occurredAt ?? now()->toIso8601String(),
            'version' => 1,
            'data' => $filteredData,
        ];
    }

    /**
     * Hapus atribut PII dari payload bila langganan tidak mengaktifkan `include_pii`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function stripPii(array $data): array
    {
        foreach (self::PII_KEYS as $key) {
            unset($data[$key]);
        }

        return $data;
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
