<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff', 'emis_gtk_id')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->string('emis_gtk_id')->nullable()->unique()->after('id');
            });
        }

        if (! Schema::hasTable('emis_import_batches')) {
            Schema::create('emis_import_batches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('entity_type', 20); // student | staff
                $table->string('source_system', 40); // emis_student | emis_gtk
                $table->string('process_type', 30); // initial_import | reconciliation
                $table->string('process_identifier', 60)->index();
                $table->boolean('allow_overwrite')->default(false);
                $table->boolean('overwrite_confirmed')->default(false);
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('created_count')->default(0);
                $table->unsignedInteger('updated_count')->default(0);
                $table->unsignedInteger('skipped_existing_count')->default(0);
                $table->unsignedInteger('matched_identical_count')->default(0);
                $table->unsignedInteger('difference_count')->default(0);
                $table->unsignedInteger('pintu_only_count')->default(0);
                $table->unsignedInteger('emis_only_count')->default(0);
                $table->unsignedInteger('needs_review_count')->default(0);
                $table->uuid('imported_by')->nullable()->index();
                $table->timestamp('imported_at');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('emis_reconciliation_items')) {
            Schema::create('emis_reconciliation_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('batch_id');
                $table->string('entity_type', 20); // student | staff
                $table->uuid('entity_id')->nullable()->index();
                $table->uuid('staging_id')->nullable()->index();
                $table->string('external_reference_id')->nullable()->index();
                $table->string('classification', 30)->index(); // same | different | pintu_only | emis_only | needs_review
                $table->json('differences')->nullable();
                $table->json('pintu_snapshot')->nullable();
                $table->json('emis_snapshot')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamps();

                $table->foreign('batch_id')
                    ->references('id')
                    ->on('emis_import_batches')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('emis_update_tasks')) {
            Schema::create('emis_update_tasks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('entity_type', 20)->index(); // student | staff
                $table->uuid('entity_id')->index();
                $table->string('source_type', 40); // student_master_update | staff_change_request | staff_master_update
                $table->uuid('source_id')->nullable()->index();
                $table->json('changed_fields');
                $table->string('status', 30)->default('pending')->index(); // pending | in_progress | completed | failed
                $table->text('operator_notes')->nullable();
                $table->string('confirmation_reference')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamp('external_updated_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('emis_update_tasks');
        Schema::dropIfExists('emis_reconciliation_items');
        Schema::dropIfExists('emis_import_batches');

        if (Schema::hasColumn('staff', 'emis_gtk_id')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropUnique(['emis_gtk_id']);
                $table->dropColumn('emis_gtk_id');
            });
        }
    }
};
