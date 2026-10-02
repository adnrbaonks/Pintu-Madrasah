<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Mata Pelajaran ──────────────────────────────────────────────
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Kode mapel: PAI, PKN, BIN, MTK, IPA, IPS, BIG, SBK, PJOK, dll');
            $table->string('name')->comment('Nama lengkap mata pelajaran');
            $table->string('type')->default('umum')->comment('umum | agama | mulok');
            $table->unsignedTinyInteger('grade_level')->comment('7, 8, 9');
            $table->unsignedSmallInteger('kkm')->default(70)->comment('KKM default per mapel');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('order')->default(0)->comment('Urutan tampil di leger');
            $table->timestamps();

            $table->index(['grade_level', 'is_active']);
        });

        // ── Nilai Siswa ─────────────────────────────────────────────────
        Schema::create('student_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('rombel_id')->constrained('rombels')->cascadeOnDelete();
            $table->string('semester', 10)->default('Ganjil')->comment('Ganjil | Genap');

            // Komponen nilai (P1 formatif, P2 formatif, STS, SAS)
            $table->decimal('nilai_p1', 5, 2)->nullable()->comment('Formatif P1');
            $table->decimal('nilai_p2', 5, 2)->nullable()->comment('Formatif P2');
            $table->decimal('nilai_sts', 5, 2)->nullable()->comment('Sumatif Tengah Semester');
            $table->decimal('nilai_sas', 5, 2)->nullable()->comment('Sumatif Akhir Semester');
            $table->decimal('nilai_akhir', 5, 2)->nullable()->storedAs(
                'ROUND((COALESCE(nilai_p1,0) + COALESCE(nilai_p2,0) + COALESCE(nilai_sts,0) + COALESCE(nilai_sas,0)) / 4, 2)'
            )->comment('Nilai akhir (rata-rata otomatis)');

            $table->string('predikat', 5)->nullable()->comment('A, B, C, D');
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'subject_id', 'academic_year_id', 'semester'], 'unique_student_grade');
            $table->index(['rombel_id', 'academic_year_id', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_grades');
        Schema::dropIfExists('subjects');
    }
};
