<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('meeting_number', 60)->unique();
            $table->string('title');
            $table->string('meeting_type', 40)->default('dinas'); // dinas, wali_murid, komite, mgmp, evaluasi
            $table->dateTime('scheduled_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('location')->default('Ruang Rapat Madrasah');
            $table->boolean('is_online')->default(false);
            $table->string('online_meeting_url', 500)->nullable();
            $table->foreignUuid('organization_unit_id')
                ->nullable()
                ->constrained('org_units')
                ->nullOnDelete();
            $table->foreignUuid('leader_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('notetaker_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('agenda')->nullable();
            $table->string('status', 30)->default('scheduled')->index(); // scheduled, ongoing, completed, cancelled
            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('meeting_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')
                ->constrained('meetings')
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('role_in_meeting', 40)->default('participant'); // leader, notetaker, participant, observer
            $table->string('attendance_status', 30)->default('invited')->index(); // invited, present, absent, excused
            $table->dateTime('attended_at')->nullable();
            $table->text('absence_reason')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
        });

        Schema::create('meeting_minutes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')
                ->constrained('meetings')
                ->cascadeOnDelete();
            $table->text('discussion_summary');
            $table->text('decisions');
            $table->foreignUuid('archive_id')
                ->nullable()
                ->constrained('archives')
                ->nullOnDelete();
            $table->string('attachment_path')->nullable();
            $table->foreignUuid('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')
                ->constrained('meetings')
                ->cascadeOnDelete();
            $table->foreignUuid('assigned_to_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->string('status', 30)->default('pending')->index(); // pending, in_progress, completed, overdue
            $table->text('resolution_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('content');
            $table->string('target_scope', 30)->default('all'); // all, gtk_only, role_specific
            $table->json('target_roles')->nullable();
            $table->string('priority', 20)->default('normal'); // low, normal, urgent
            $table->boolean('is_pinned')->default(false);
            $table->date('publish_date')->index();
            $table->date('expires_at')->nullable()->index();
            $table->foreignUuid('author_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('archive_id')
                ->nullable()
                ->constrained('archives')
                ->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('event_type', 40)->default('agenda'); // agenda, academic, holiday, meeting, exam
            $table->dateTime('start_time')->index();
            $table->dateTime('end_time')->nullable()->index();
            $table->boolean('is_all_day')->default(false);
            $table->string('color', 20)->default('#4f46e5');
            $table->foreignUuid('meeting_id')
                ->nullable()
                ->constrained('meetings')
                ->nullOnDelete();
            $table->foreignUuid('academic_year_id')
                ->nullable()
                ->constrained('academic_years')
                ->nullOnDelete();
            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('meeting_actions');
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
