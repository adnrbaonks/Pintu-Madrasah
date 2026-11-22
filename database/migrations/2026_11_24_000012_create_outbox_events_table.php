<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type')->comment('Contoh: App\Models\Staff | App\Models\Student');
            $table->uuid('aggregate_id');
            $table->string('event_type')->comment('Contoh: staff.updated');
            $table->json('payload');
            $table->string('status')->default('pending')->comment('pending | published | failed');
            $table->timestamp('available_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['status', 'available_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
