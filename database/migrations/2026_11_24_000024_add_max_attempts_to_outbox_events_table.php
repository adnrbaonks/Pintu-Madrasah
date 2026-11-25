<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbox_events') || Schema::hasColumn('outbox_events', 'max_attempts')) {
            return;
        }

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_attempts')->default(5)->after('attempts');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outbox_events') || ! Schema::hasColumn('outbox_events', 'max_attempts')) {
            return;
        }

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn('max_attempts');
        });
    }
};
