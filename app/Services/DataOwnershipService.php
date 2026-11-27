<?php

namespace App\Services;

class DataOwnershipService
{
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
     * Ambil daftar field yang boleh diedit langsung (change_policy === 'direct').
     *
     * @return array<int, string>
     */
    public function getEditableFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return array_keys(array_filter(
            $dictionary,
            fn (array $meta): bool => ($meta['change_policy'] ?? '') === 'direct'
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

        return ($fieldConfig['change_policy'] ?? '') === 'direct';
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
