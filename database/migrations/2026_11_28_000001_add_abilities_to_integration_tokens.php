<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // abilities NULL = token lama (legacy) => hanya ability baca (lihat IntegrationToken::LEGACY_ABILITIES).
        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->json('abilities')->nullable()->after('purpose');
        });

        foreach (['emis_staging', 'emis_gtk_stagings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('import_session_id', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['emis_staging', 'emis_gtk_stagings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['import_session_id']);
                $table->dropColumn('import_session_id');
            });
        }
        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });
    }
};
