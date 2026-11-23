<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pkkm_instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('title');
            $table->string('period_year', 20)->index();
            $table->string('assessment_type', 40)->default('annual');
            $table->foreignUuid('academic_year_id')
                ->nullable()
                ->constrained('academic_years')
                ->nullOnDelete();
            $table->foreignUuid('headmaster_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->decimal('overall_score', 6, 2)->default(0);
            $table->string('predicate', 40)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUuid('finalized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pkkm_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pkkm_instrument_id')
                ->constrained('pkkm_instruments')
                ->cascadeOnDelete();
            $table->string('category_code', 40)->index();
            $table->string('category_name');
            $table->string('code', 40);
            $table->string('title');
            $table->text('indicator_description')->nullable();
            $table->text('required_evidence_hint')->nullable();
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->unsignedTinyInteger('max_score')->default(100);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->foreignUuid('pic_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['pkkm_instrument_id', 'code']);
        });

        Schema::create('pkkm_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pkkm_instrument_id')
                ->constrained('pkkm_instruments')
                ->cascadeOnDelete();
            $table->foreignUuid('pkkm_component_id')
                ->constrained('pkkm_components')
                ->cascadeOnDelete();
            $table->decimal('self_score', 5, 2)->default(0);
            $table->decimal('verified_score', 5, 2)->nullable();
            $table->text('self_notes')->nullable();
            $table->text('assessor_notes')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignUuid('assessed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['pkkm_instrument_id', 'pkkm_component_id']);
        });

        Schema::create('pkkm_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pkkm_component_id')
                ->constrained('pkkm_components')
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
            $table->text('notes')->nullable();
            $table->string('verification_status', 30)->default('pending');
            $table->foreignUuid('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pkkm_evidence');
        Schema::dropIfExists('pkkm_assessments');
        Schema::dropIfExists('pkkm_components');
        Schema::dropIfExists('pkkm_instruments');
    }
};
