<?php

namespace App\Services;

class DataOwnershipService
{
    public const MODE_DEVELOPMENT = 'development';

    public const MODE_MIGRATION = 'migration';

    public const MODE_PRODUCTION = 'production';

    /**
     * Ambil mode siklus hidup data ownership saat ini ('development', 'migration', atau 'production').
     */
    public function lifecycleMode(): string
    {
        $mode = strtolower(trim((string) config('data_ownership.lifecycle_mode', self::MODE_PRODUCTION)));

        return in_array($mode, [self::MODE_DEVELOPMENT, self::MODE_MIGRATION, self::MODE_PRODUCTION], true)
            ? $mode
            : self::MODE_PRODUCTION;
    }

    /**
     * Apakah sistem berada dalam mode produksi (PINTU sebagai SSOT penuh)?
     */
    public function isProductionMode(): bool
    {
        return $this->lifecycleMode() === self::MODE_PRODUCTION;
    }

    /**
     * Apakah opsi darurat break-glass import dari EMIS diaktifkan di konfigurasi?
     */
    public function isBreakGlassEnabled(): bool
    {
        return (bool) config('data_ownership.break_glass.enabled', false);
    }

    /**
     * Nama permission eksplisit untuk melakukan break-glass import dari EMIS.
     */
    public function breakGlassPermission(): string
    {
        return (string) config('data_ownership.break_glass.permission', 'break_glass_emis_import');
    }

    /**
     * Panjang minimal alasan break-glass import.
     */
    public function breakGlassMinReasonLength(): int
    {
        return max(10, (int) config('data_ownership.break_glass.min_reason_length', 15));
    }

    /**
     * Apakah mutasi master data PINTU dari EMIS diizinkan pada konteks eksekusi ini?
     */
    public function canImportFromEmis(bool $isBreakGlass = false): bool
    {
        if (! $this->isProductionMode()) {
            return true;
        }

        return $isBreakGlass && $this->isBreakGlassEnabled();
    }

    /**
     * Ambil seluruh kamus data ownership untuk satu entitas (staff / student / academic).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getEntityDictionary(string $entity): array
    {
        return config("data_ownership.{$entity}", []);
    }

    /**
     * Ambil konfigurasi satu field pada entitas.
     *
     * @return array<string, mixed>|null
     */
    public function getFieldConfig(string $entity, string $field): ?array
    {
        return config("data_ownership.{$entity}.{$field}");
    }

    /**
     * Apakah field ini dimiliki oleh PINTU MADRASAH sebagai SSOT saat produksi?
     */
    public function isOwnedByPintu(string $entity, string $field): bool
    {
        $fieldConfig = $this->getFieldConfig($entity, $field);

        return ($fieldConfig['owner_system'] ?? null) === 'local';
    }

    /**
     * Ambil daftar field yang boleh diedit langsung (change_policy === 'direct' dan tidak memerlukan approval berjenjang).
     *
     * @return array<int, string>
     */
    public function getEditableFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return array_keys(array_filter(
            $dictionary,
            fn (array $meta): bool => ($meta['change_policy'] ?? '') === 'direct'
                && ! (bool) ($meta['approval_required'] ?? false)
        ));
    }

    /**
     * Ambil daftar field yang tidak dapat diedit langsung oleh form operator biasa
     * (misal ID referensi eksternal read_only atau atribut GTK yang wajib melalui alur persetujuan).
     *
     * @return array<int, string>
     */
    public function getReadOnlyFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return array_keys(array_filter(
            $dictionary,
            fn (array $meta): bool => in_array($meta['change_policy'] ?? '', ['read_only', 'approval_required'], true)
                || (bool) ($meta['approval_required'] ?? false)
        ));
    }

    /**
     * Ambil daftar field yang mewajibkan alur pengajuan & persetujuan berjenjang.
     *
     * @return array<int, string>
     */
    public function getApprovalRequiredFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return array_keys(array_filter(
            $dictionary,
            fn (array $meta): bool => ($meta['change_policy'] ?? '') === 'approval_required'
                || (bool) ($meta['approval_required'] ?? false)
        ));
    }

    /**
     * Ambil daftar field sensitif / berisiko tinggi yang memerlukan verifikasi dokumen.
     *
     * @return array<int, string>
     */
    public function getVerificationRequiredFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return array_keys(array_filter(
            $dictionary,
            fn (array $meta): bool => (bool) ($meta['verification_required'] ?? false)
        ));
    }

    /**
     * Ambil daftar field yang mewajibkan lampiran dokumen pendukung saat diubah.
     *
     * @return array<int, string>
     */
    public function getDocumentRequiredFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return array_keys(array_filter(
            $dictionary,
            fn (array $meta): bool => (bool) ($meta['document_required'] ?? false)
        ));
    }

    /**
     * Ambil daftar field yang perubahannya perlu diperbarui ke EMIS (outbound / manual_outbound).
     *
     * @return array<int, string>
     */
    public function getOutboundEmisFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return array_keys(array_filter(
            $dictionary,
            fn (array $meta): bool => in_array($meta['production_sync_direction'] ?? ($meta['sync_direction'] ?? ''), ['outbound', 'manual_outbound'], true)
                && str_starts_with((string) ($meta['target_system'] ?? ''), 'EMIS')
        ));
    }

    /**
     * Apakah field tertentu boleh diubah langsung melalui form pengelolaan operator?
     */
    public function isEditable(string $entity, string $field): bool
    {
        $fieldConfig = $this->getFieldConfig($entity, $field);

        if (! is_array($fieldConfig)) {
            return false;
        }

        return ($fieldConfig['change_policy'] ?? '') === 'direct'
            && ! (bool) ($fieldConfig['approval_required'] ?? false);
    }

    /**
     * Apakah field tertentu merupakan atribut sensitif?
     */
    public function isSensitive(string $entity, string $field): bool
    {
        $fieldConfig = $this->getFieldConfig($entity, $field);

        return (bool) ($fieldConfig['sensitive'] ?? false);
    }

    /**
     * Apakah field tertentu membutuhkan verifikasi dokumen pendukung?
     */
    public function requiresVerification(string $entity, string $field): bool
    {
        $fieldConfig = $this->getFieldConfig($entity, $field);

        return (bool) ($fieldConfig['verification_required'] ?? false);
    }

    /**
     * Apakah field tertentu mewajibkan dokumen pendukung saat diajukan?
     */
    public function requiresDocument(string $entity, string $field): bool
    {
        $fieldConfig = $this->getFieldConfig($entity, $field);

        return (bool) ($fieldConfig['document_required'] ?? false);
    }

    /**
     * Apakah perubahan field tertentu di PINTU memerlukan pembaruan keluar ke EMIS?
     */
    public function requiresOutboundEmisUpdate(string $entity, string $field): bool
    {
        $fieldConfig = $this->getFieldConfig($entity, $field);

        if (! is_array($fieldConfig)) {
            return false;
        }

        $direction = (string) ($fieldConfig['production_sync_direction'] ?? ($fieldConfig['sync_direction'] ?? ''));
        $target = (string) ($fieldConfig['target_system'] ?? '');

        return in_array($direction, ['outbound', 'manual_outbound'], true) && str_starts_with($target, 'EMIS');
    }

    /**
     * Filter payload input agar hanya menyisakan atribut yang diizinkan diedit langsung.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function filterAllowedAttributes(string $entity, array $attributes): array
    {
        $allowedKeys = array_flip($this->getEditableFields($entity));

        return array_intersect_key($attributes, $allowedKeys);
    }
}
