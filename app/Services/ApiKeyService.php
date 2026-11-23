<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ApiClient;
use App\Models\ApiScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * Hasilkan pasangan kunci API terstruktur: pm_live_<prefix8>_<secret40>.
     *
     * @return array{plain_key: string, key_hash: string, key_prefix: string, key_last4: string}
     */
    public function generateKeyMaterial(): array
    {
        $prefix = Str::lower(Str::random(8));
        $secret = Str::random(40);
        $plainKey = "pm_live_{$prefix}_{$secret}";

        return [
            'plain_key' => $plainKey,
            'key_hash' => hash('sha256', $plainKey),
            'key_prefix' => $prefix,
            'key_last4' => substr($plainKey, -4),
        ];
    }

    /**
     * Buat klien API baru tanpa menyimpan plaintext `api_key` di database.
     *
     * @param  array{name: string, scopes: array<int, string>, notes?: ?string, expires_at?: ?string}  $data
     * @return array{client: ApiClient, plain_key: string}
     */
    public function createClient(array $data, ?string $actorUserId = null): array
    {
        $material = $this->generateKeyMaterial();

        return DB::transaction(function () use ($data, $material, $actorUserId): array {
            $client = ApiClient::create([
                'name' => $data['name'],
                'api_key' => null,
                'key_hash' => $material['key_hash'],
                'key_prefix' => $material['key_prefix'],
                'key_last4' => $material['key_last4'],
                'scopes' => array_values($data['scopes']),
                'is_active' => true,
                'last_rotated_at' => now(),
                'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncScopePivot($client, $data['scopes']);

            ActivityLog::create([
                'user_id' => $actorUserId ?? auth()->id(),
                'action' => 'created_api_client',
                'model_type' => ApiClient::class,
                'model_id' => $client->id,
                'before_payload' => null,
                'after_payload' => [
                    'name' => $client->name,
                    'key_prefix' => $client->key_prefix,
                    'scopes' => $client->scopes,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return [
                'client' => $client,
                'plain_key' => $material['plain_key'],
            ];
        });
    }

    /**
     * Rotasi (regenerate) kunci API klien: hash baru disimpan, plaintext lama dihapus.
     *
     * @return array{client: ApiClient, plain_key: string}
     */
    public function rotate(ApiClient $client, ?string $actorUserId = null): array
    {
        $material = $this->generateKeyMaterial();
        $oldPrefix = $client->key_prefix;

        return DB::transaction(function () use ($client, $material, $oldPrefix, $actorUserId): array {
            $client->update([
                'api_key' => null,
                'key_hash' => $material['key_hash'],
                'key_prefix' => $material['key_prefix'],
                'key_last4' => $material['key_last4'],
                'last_rotated_at' => now(),
                'revoked_at' => null,
                'is_active' => true,
            ]);

            ActivityLog::create([
                'user_id' => $actorUserId ?? auth()->id(),
                'action' => 'rotated_api_client_key',
                'model_type' => ApiClient::class,
                'model_id' => $client->id,
                'before_payload' => ['key_prefix' => $oldPrefix],
                'after_payload' => ['key_prefix' => $material['key_prefix']],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return [
                'client' => $client->fresh(),
                'plain_key' => $material['plain_key'],
            ];
        });
    }

    /**
     * Cabut (revoke) atau aktifkan kembali API client.
     */
    public function setActive(ApiClient $client, bool $active, ?string $actorUserId = null): ApiClient
    {
        $client->update([
            'is_active' => $active,
            'revoked_at' => $active ? null : now(),
        ]);

        ActivityLog::create([
            'user_id' => $actorUserId ?? auth()->id(),
            'action' => $active ? 'activated_api_client' : 'revoked_api_client',
            'model_type' => ApiClient::class,
            'model_id' => $client->id,
            'before_payload' => ['is_active' => ! $active],
            'after_payload' => ['is_active' => $active],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        return $client->fresh();
    }

    /**
     * Cari dan verifikasi API key secara constant-time (mendukung key_hash maupun dual-read plaintext transisi).
     */
    public function findMatchingClient(string $providedKey): ?ApiClient
    {
        $hash = hash('sha256', $providedKey);

        // 1. Lookup via key_hash
        $candidate = ApiClient::where('key_hash', $hash)->first();
        if ($candidate && hash_equals((string) $candidate->key_hash, $hash)) {
            return $candidate;
        }

        // 2. Fallback dual-read untuk baris transisi yang hanya memiliki api_key plaintext
        $legacyCandidate = ApiClient::whereNotNull('api_key')
            ->where('api_key', $providedKey)
            ->first();

        if ($legacyCandidate && hash_equals((string) $legacyCandidate->api_key, $providedKey)) {
            return $legacyCandidate;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $scopeCodes
     */
    public function syncScopePivot(ApiClient $client, array $scopeCodes): void
    {
        $scopeIds = ApiScope::whereIn('code', $scopeCodes)->pluck('id')->all();
        if (! empty($scopeIds)) {
            $client->scopeModels()->sync($scopeIds);
        }
    }
}
