<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Perbaiki student_grades: academic_year_id & rombel_id harus UUID ──
        // Tabel masih kosong, drop + recreate dengan skema benar (kompatibel MySQL prod).
        Schema::dropIfExists('student_grades');

        Schema::create('student_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('rombel_id')->constrained('rombels')->cascadeOnDelete();
            $table->string('semester', 10)->default('Ganjil');

            $table->decimal('nilai_p1', 5, 2)->nullable();
            $table->decimal('nilai_p2', 5, 2)->nullable();
            $table->decimal('nilai_sts', 5, 2)->nullable();
            $table->decimal('nilai_sas', 5, 2)->nullable();
            $table->decimal('nilai_akhir', 5, 2)->nullable();
            $table->string('predikat', 5)->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'subject_id', 'academic_year_id', 'semester'], 'unique_student_grade');
            $table->index(['rombel_id', 'academic_year_id', 'semester']);
        });

        // ── Perbaiki emis_staging: samakan kolom dengan kode (Pull/Commit Job + Controller) ──
        Schema::table('emis_staging', function (Blueprint $table) {
            if (! Schema::hasColumn('emis_staging', 'nik')) {
                $table->string('nik', 16)->nullable()->after('nisn');
            }
            if (! Schema::hasColumn('emis_staging', 'name')) {
                $table->string('name')->nullable()->after('nik');
            }
            if (! Schema::hasColumn('emis_staging', 'gender')) {
                $table->string('gender', 10)->nullable()->after('name');
            }
            if (! Schema::hasColumn('emis_staging', 'rombel_name')) {
                $table->string('rombel_name')->nullable()->after('gender');
            }
            if (! Schema::hasColumn('emis_staging', 'status')) {
                $table->string('status', 20)->default('pending')->after('raw_data');
            }
        });
    }

    public function down(): void
    {
        Schema::table('emis_staging', function (Blueprint $table) {
            $table->dropColumn(['nik', 'name', 'gender', 'rombel_name', 'status']);
        });
    }
};
