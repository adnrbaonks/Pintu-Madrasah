<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->timestamp('reserved_at')->nullable()->after('available_at');
            $table->timestamp('lease_until')->nullable()->after('reserved_at');
            $table->string('leased_by')->nullable()->after('lease_until');

            $table->index(['status', 'lease_until'], 'outbox_events_status_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex('outbox_events_status_lease_index');
            $table->dropColumn(['reserved_at', 'lease_until', 'leased_by']);
        });
    }
};
