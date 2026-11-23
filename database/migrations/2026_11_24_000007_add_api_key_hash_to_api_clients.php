<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->string('api_key', 100)->nullable()->change();
            $table->string('key_hash', 64)->nullable()->unique()->after('api_key');
            $table->string('key_prefix', 16)->nullable()->index()->after('key_hash');
            $table->string('key_last4', 8)->nullable()->after('key_prefix');
            $table->timestamp('last_rotated_at')->nullable()->after('last_used_at');
            $table->timestamp('expires_at')->nullable()->after('last_rotated_at');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });

        // Backfill baris yang sudah ada agar langsung memiliki key_hash, key_prefix, key_last4
        $clients = DB::table('api_clients')->whereNotNull('api_key')->get();
        foreach ($clients as $client) {
            $raw = (string) $client->api_key;
            $prefix = str_starts_with($raw, 'pm_live_')
                ? substr($raw, 8, 8)
                : substr($raw, 0, 8);

            DB::table('api_clients')
                ->where('id', $client->id)
                ->update([
                    'key_hash' => hash('sha256', $raw),
                    'key_prefix' => $prefix,
                    'key_last4' => substr($raw, -4),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropUnique(['key_hash']);
            $table->dropIndex(['key_prefix']);
            $table->dropColumn([
                'key_hash',
                'key_prefix',
                'key_last4',
                'last_rotated_at',
                'expires_at',
                'revoked_at',
            ]);
        });
    }
};
