<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class HashExistingApiKeysCommand extends Command
{
    protected $signature = 'api-keys:hash-existing {--keep-plaintext : Jangan hapus kolom api_key plaintext setelah di-hash}';

    protected $description = 'Backfill key_hash SHA-256 dari api_key plaintext pada tabel api_clients lalu kosongkan plaintext.';

    public function handle(ApiKeyService $apiKeyService): int
    {
        $clients = ApiClient::query()->get();
        $hashedCount = 0;
        $clearedCount = 0;

        foreach ($clients as $client) {
            $raw = $client->getRawOriginal('api_key');

            if (filled($raw)) {
                $computedHash = hash('sha256', (string) $raw);
                $prefix = str_starts_with((string) $raw, 'pm_live_')
                    ? substr((string) $raw, 8, 8)
                    : substr((string) $raw, 0, 8);
                $last4 = substr((string) $raw, -4);

                $client->key_hash = $computedHash;
                $client->key_prefix = $client->key_prefix ?: $prefix;
                $client->key_last4 = $client->key_last4 ?: $last4;
                $client->save();

                // Verifikasi hash cocok sebelum mengosongkan plaintext
                if (hash_equals((string) $client->fresh()->key_hash, $computedHash)) {
                    $hashedCount++;

                    if (! $this->option('keep-plaintext')) {
                        $client->update(['api_key' => null]);
                        $clearedCount++;
                    }
                }
            }

            if (! empty($client->scopes)) {
                $apiKeyService->syncScopePivot($client, $client->scopes);
            }
        }

        $this->info("Selesai memproses {$clients->count()} klien API ({$hashedCount} di-hash, {$clearedCount} plaintext dikosongkan).");

        return self::SUCCESS;
    }
}
