<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_minute_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_minute_id')
                ->constrained('meeting_minutes')
                ->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->text('previous_discussion_summary');
            $table->text('previous_decisions');
            $table->foreignUuid('previous_archive_id')
                ->nullable()
                ->constrained('archives')
                ->nullOnDelete();
            $table->foreignUuid('previous_approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('previous_approved_at')->nullable();
            $table->text('revision_notes');
            $table->foreignUuid('revised_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_minute_revisions');
    }
};
