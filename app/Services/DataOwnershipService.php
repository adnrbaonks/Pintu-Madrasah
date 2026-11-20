<?php

namespace App\Services;

class DataOwnershipService
{
    /**
     * Dapatkan kamus aturan untuk entitas tertentu.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getEntityDictionary(string $entity): array
    {
        return config("data_ownership.{$entity}", []);
    }

    /**
     * Dapatkan daftar nama kolom yang boleh diubah secara langsung oleh madrasah.
     *
     * @return array<int, string>
     */
    public function getEditableFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return collect($dictionary)
            ->filter(fn (array $policy) => ($policy['change_policy'] ?? '') === 'direct')
            ->keys()
            ->all();
    }

    /**
     * Dapatkan daftar nama kolom yang berstatus read-only (otoritas luar).
     *
     * @return array<int, string>
     */
    public function getReadOnlyFields(string $entity): array
    {
        $dictionary = $this->getEntityDictionary($entity);

        return collect($dictionary)
            ->filter(fn (array $policy) => ($policy['change_policy'] ?? '') === 'read_only')
            ->keys()
            ->all();
    }

    /**
     * Periksa apakah satu kolom boleh diedit langsung secara lokal.
     */
    public function isEditable(string $entity, string $field): bool
    {
        $policy = config("data_ownership.{$entity}.{$field}");

        if (! $policy) {
            // Default jika tidak terdaftar: jika field ada di lokal dan bukan kolom kunci
            return true;
        }

        return ($policy['change_policy'] ?? '') === 'direct';
    }

    /**
     * Filter input array sehingga hanya kolom yang diizinkan untuk diedit yang lolos.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function filterAllowedAttributes(string $entity, array $attributes): array
    {
        $editable = $this->getEditableFields($entity);

        return collect($attributes)
            ->only($editable)
            ->all();
    }
}
