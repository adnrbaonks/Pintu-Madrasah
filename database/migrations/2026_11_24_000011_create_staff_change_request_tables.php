<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignUuid('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 40)->default('draft');
            $table->text('reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('current_stage', 40)->nullable()->default('draft');
            $table->timestamp('decided_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'status']);
            $table->index(['requester_user_id', 'status']);
            $table->index(['status', 'current_stage']);
        });

        Schema::create('staff_change_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('staff_change_requests')->cascadeOnDelete();
            $table->string('field', 100);
            $table->text('old_value')->nullable();
            $table->text('proposed_value')->nullable();
            $table->string('owner_system', 50)->default('local');
            $table->string('change_policy', 50)->default('direct');
            $table->string('apply_status', 40)->default('pending');
            $table->text('apply_error')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'field']);
            $table->index('apply_status');
        });

        Schema::create('staff_change_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('staff_change_requests')->cascadeOnDelete();
            $table->uuid('item_id')->nullable();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('item_id')
                ->references('id')
                ->on('staff_change_request_items')
                ->nullOnDelete();

            $table->index('request_id');
        });

        Schema::create('staff_change_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('staff_change_requests')->cascadeOnDelete();
            $table->string('stage', 40);
            $table->foreignUuid('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 40);
            $table->text('notes')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['request_id', 'stage']);
            $table->index('actor_user_id');
        });

        Schema::create('staff_change_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('staff_change_requests')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('staff_change_request_items')->cascadeOnDelete();
            $table->text('applied_value')->nullable();
            $table->uuid('applied_by')->nullable();
            $table->timestamp('applied_at');
            $table->string('source', 30)->default('local');
            $table->timestamps();

            $table->index('request_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_change_histories');
        Schema::dropIfExists('staff_change_approvals');
        Schema::dropIfExists('staff_change_attachments');
        Schema::dropIfExists('staff_change_request_items');
        Schema::dropIfExists('staff_change_requests');
    }
};
