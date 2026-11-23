<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('target_url');
            $table->boolean('include_pii')->default(false)->after('is_active');
            $table->unsignedTinyInteger('max_attempts')->default(5)->after('include_pii');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('event_id')->nullable()->after('webhook_subscription_id');
            $table->uuid('outbox_event_id')->nullable()->index()->after('event_id');
            $table->unsignedTinyInteger('attempt')->default(1)->after('delivered');
            $table->timestamp('next_retry_at')->nullable()->after('attempt');
            $table->timestamp('delivered_at')->nullable()->after('next_retry_at');
            $table->json('response_headers')->nullable()->after('response_body');

            $table->unique(['webhook_subscription_id', 'event_id'], 'wh_deliveries_sub_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique('wh_deliveries_sub_event_unique');
            $table->dropIndex(['outbox_event_id']);
            $table->dropColumn([
                'event_id',
                'outbox_event_id',
                'attempt',
                'next_retry_at',
                'delivered_at',
                'response_headers',
            ]);
        });

        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'include_pii',
                'max_attempts',
            ]);
        });
    }
};
