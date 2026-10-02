<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('api_client_id')->nullable()->constrained('api_clients')->nullOnDelete();
            $table->string('event', 40)->comment('student.created | student.updated | student.mutated | student.promoted');
            $table->string('target_url', 500);
            $table->string('secret', 100)->comment('HMAC-SHA256 signing secret');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('last_status')->nullable();
            $table->timestamps();

            $table->index(['event', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->string('event', 40);
            $table->json('payload');
            $table->integer('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->boolean('delivered')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['webhook_subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
