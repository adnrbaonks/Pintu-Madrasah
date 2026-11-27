<?php

namespace App\Services;

/**
 * Sanitasi PII untuk respons API EMIS (snapshot, differences, changed_fields, match_value, dll).
 *
 * Daftar atribut sensitif diturunkan dari data ownership dictionary (flag `sensitive`)
 * ditambah atribut identitas yang secara eksplisit diperlakukan sebagai PII.
 *
 * Level:
 * - LEVEL_OPERATOR (sesi): identifier dimasking parsial; atribut sensitif lain disamarkan penuh;
 *   nama & tempat/tanggal lahir tetap tampil agar operator dapat mengenali perbedaan data.
 * - LEVEL_INTEGRATION (token): seluruh PII (termasuk nama & tanggal lahir) disamarkan.
 */
class EmisPiiSanitizer
{
    public const LEVEL_OPERATOR = 'operator';

    public const LEVEL_INTEGRATION = 'integration';

    public const REDACTED = '[REDACTED]';

    /**
     * Atribut identitas yang dianggap PII walau tidak bertanda `sensitive` di dictionary.
     *
     * @var array<int, string>
     */
    private const EXTRA_PII_KEYS = [
        'name', 'full_name_with_titles', 'first_title', 'last_title', 'nickname', 'university_name',
        'study_program', 'tpg_status', 'civil_rank', 'raw_data', 'existing_name',
    ];

    /**
     * Atribut yang tetap terlihat bagi operator sesi.
     *
     * @var array<int, string>
     */
    private const OPERATOR_VISIBLE_KEYS = [
        'name', 'full_name_with_titles', 'first_title', 'last_title', 'birth_place', 'birth_date',
        'university_name', 'study_program', 'tpg_status', 'civil_rank', 'existing_name',
    ];

    /**
     * @var array<int, string>|null
     */
    private ?array $sensitiveKeys = null;

    public function __construct(private readonly DataOwnershipService $ownership) {}

    /**
     * @return array<int, string>
     */
    public function sensitiveKeys(): array
    {
        if ($this->sensitiveKeys !== null) {
            return $this->sensitiveKeys;
        }

        $keys = self::EXTRA_PII_KEYS;
        foreach (['student', 'staff'] as $entity) {
            foreach ($this->ownership->getEntityDictionary($entity) as $field => $meta) {
                if (is_array($meta) && ($meta['sensitive'] ?? false)) {
                    $keys[] = (string) $field;
                }
            }
        }

        return $this->sensitiveKeys = array_values(array_unique($keys));
    }

    public function isSensitiveKey(string $key): bool
    {
        return in_array($key, $this->sensitiveKeys(), true);
    }

    /**
     * Sanitasi struktur data (rekursif) berdasarkan nama kunci.
     */
    public function sanitize(mixed $data, string $level, ?string $contextField = null, ?string $currentKey = null): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $field = is_string($key) ? $key : null;
                $nextContext = $contextField;
                if ($field !== null && $this->isSensitiveKey($field)) {
                    $nextContext = $field;
                }
                $result[$key] = $this->sanitize($value, $level, $nextContext, $field);
            }

            return $result;
        }

        if ($contextField === null || $data === null || $data === '' || $currentKey === 'diff_type') {
            return $data;
        }

        return $this->maskValue($contextField, $data, $level);
    }

    /**
     * Sanitasi item rekonsiliasi lengkap, termasuk match_key/match_value.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function sanitizeItem(array $item, string $level): array
    {
        $matchKey = $item['match_key'] ?? null;
        $matchValue = $item['match_value'] ?? null;

        $item = $this->sanitize($item, $level);

        if (is_string($matchKey) && $this->isSensitiveKey($matchKey) && array_key_exists('match_value', $item)) {
            $item['match_value'] = $this->maskValue($matchKey, $matchValue, $level);
        }

        if (filled($item['external_reference_id'] ?? null)) {
            $item['external_reference_id'] = $this->maskValue('nik', $item['external_reference_id'], $level);
        }

        return $item;
    }

    public function maskValue(string $field, mixed $value, string $level): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_array($value)) {
            return self::REDACTED;
        }

        if ($level === self::LEVEL_OPERATOR && in_array($field, self::OPERATOR_VISIBLE_KEYS, true)) {
            return $value;
        }

        if ($this->isIdentifier($field)) {
            $str = (string) $value;
            $len = mb_strlen($str);
            if ($len <= 6) {
                return str_repeat('*', $len);
            }

            return mb_substr($str, 0, 2).str_repeat('*', max(4, $len - 6)).mb_substr($str, -4);
        }

        return self::REDACTED;
    }

    private function isIdentifier(string $field): bool
    {
        return (bool) preg_match('/(^|_)(nik|nisn|nip|nuptk|npk)$|^(kk_number|no_kk|kip_number)$/', $field);
    }
}
