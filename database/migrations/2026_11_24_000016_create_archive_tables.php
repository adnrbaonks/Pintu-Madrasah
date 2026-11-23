<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->string('default_visibility', 30)->default('internal');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('archives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('archive_category_id')
                ->constrained('archive_categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')
                ->nullable()
                ->constrained('org_units')
                ->nullOnDelete();
            $table->foreignUuid('academic_year_id')
                ->nullable()
                ->constrained('academic_years')
                ->nullOnDelete();
            $table->string('archive_number', 100)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('classification_code', 50)->nullable();
            $table->string('visibility', 30)->default('internal')->index();
            $table->json('allowed_roles')->nullable();
            $table->foreignUuid('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('owner_staff_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();
            $table->unsignedInteger('current_version')->default(1);
            $table->date('document_date')->nullable();
            $table->date('retention_until')->nullable()->index();
            $table->string('status', 30)->default('active')->index();
            $table->json('tags')->nullable();
            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['archive_category_id', 'status']);
        });

        Schema::create('archive_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('archive_id')
                ->constrained('archives')
                ->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('storage_disk', 30)->default('local');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('google_drive_id')->nullable();
            $table->string('google_drive_link')->nullable();
            $table->text('change_notes')->nullable();
            $table->foreignUuid('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['archive_id', 'version_number']);
        });

        Schema::create('archive_access_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('archive_id')
                ->constrained('archives')
                ->cascadeOnDelete();
            $table->foreignUuid('archive_version_id')
                ->nullable()
                ->constrained('archive_versions')
                ->nullOnDelete();
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('action', 40)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['archive_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_access_logs');
        Schema::dropIfExists('archive_versions');
        Schema::dropIfExists('archives');
        Schema::dropIfExists('archive_categories');
    }
};
