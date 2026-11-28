<?php

use App\Services\EmisImportSessionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emis_import_sessions', function (Blueprint $table) {
            $table->timestamp('finalizing_at')->nullable()->after('started_at')->index();
            $table->timestamp('failed_at')->nullable()->after('completed_at')->index();
            $table->unsignedInteger('saved_rows')->nullable()->after('received_rows');

            $table->index(['status', 'expires_at'], 'emis_import_sessions_status_expires_idx');
            $table->index(['status', 'completed_at'], 'emis_import_sessions_status_completed_idx');
            $table->index(['status', 'failed_at'], 'emis_import_sessions_status_failed_idx');
        });

        Schema::table('emis_import_session_pages', function (Blueprint $table) {
            $table->string('payload_hash', 64)->default('')->after('items_count');
        });

        $hasher = app(EmisImportSessionService::class);

        DB::table('emis_import_session_pages')
            ->where('payload_hash', '')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($hasher): void {
                foreach ($rows as $row) {
                    $decoded = is_string($row->payload)
                        ? json_decode($row->payload, true)
                        : (is_array($row->payload) ? $row->payload : null);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    DB::table('emis_import_session_pages')
                        ->where('id', $row->id)
                        ->update(['payload_hash' => $hasher->computePayloadHash($decoded)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('emis_import_session_pages', function (Blueprint $table) {
            $table->dropColumn('payload_hash');
        });

        Schema::table('emis_import_sessions', function (Blueprint $table) {
            $table->dropIndex('emis_import_sessions_status_expires_idx');
            $table->dropIndex('emis_import_sessions_status_completed_idx');
            $table->dropIndex('emis_import_sessions_status_failed_idx');
            $table->dropColumn(['finalizing_at', 'failed_at', 'saved_rows']);
        });
    }
};
