<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignUuid('academic_year_id')
                ->nullable()
                ->constrained('academic_years')
                ->nullOnDelete();
            $table->foreignUuid('organization_unit_id')
                ->nullable()
                ->constrained('org_units')
                ->nullOnDelete();
            $table->foreignUuid('pic_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('budget_amount')->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->foreignUuid('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('program_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')
                ->constrained('programs')
                ->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('target_indicator', 255)->nullable();
            $table->foreignUuid('pic_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->date('scheduled_start')->nullable();
            $table->date('scheduled_end')->nullable();
            $table->unsignedBigInteger('budget_allocation')->default(0);
            $table->unsignedBigInteger('budget_realization')->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('status', 30)->default('planned')->index();
            $table->timestamps();
        });

        Schema::create('program_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')
                ->constrained('programs')
                ->cascadeOnDelete();
            $table->foreignUuid('program_activity_id')
                ->nullable()
                ->constrained('program_activities')
                ->nullOnDelete();
            $table->string('title');
            $table->date('report_date');
            $table->text('summary');
            $table->text('outcomes')->nullable();
            $table->text('obstacles')->nullable();
            $table->unsignedBigInteger('realized_budget')->default(0);
            $table->unsignedTinyInteger('achieved_percent')->default(0);
            $table->string('status', 30)->default('submitted')->index();
            $table->foreignUuid('submitted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('program_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_report_id')
                ->constrained('program_reports')
                ->cascadeOnDelete();
            $table->foreignUuid('archive_id')
                ->nullable()
                ->constrained('archives')
                ->nullOnDelete();
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('external_url', 500)->nullable();
            $table->foreignUuid('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_evidence');
        Schema::dropIfExists('program_reports');
        Schema::dropIfExists('program_activities');
        Schema::dropIfExists('programs');
    }
};
