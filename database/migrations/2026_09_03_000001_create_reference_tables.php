<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Tahun Ajaran ────────────────────────────────────────────────
        Schema::create('academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('year', 9)->comment('Format: 2026/2027');
            $table->string('semester')->comment('Ganjil | Genap');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_locked')->default(false)
                  ->comment('Kunci data nilai & rombel semester lampau menjadi Read-Only');
            $table->timestamps();

            $table->unique(['year', 'semester']);
            $table->index('is_active');
        });

        // ── Kelas / Tingkatan ────────────────────────────────────────────
        Schema::create('classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('grade_level')->comment('VII | VIII | IX | X | XI | XII');
            $table->string('major_name')->nullable()->comment('Jurusan (untuk MA/SMK)');
            $table->timestamps();

            $table->index('grade_level');
        });

        // ── Rombongan Belajar ─────────────────────────────────────────────
        Schema::create('rombels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('name')->comment('Contoh: VII A, VIII B');
            $table->uuid('homeroom_teacher_id')->nullable()->comment('FK ke staff.id — ditambah setelah tabel staff dibuat');
            $table->timestamps();

            $table->index(['academic_year_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rombels');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('academic_years');
    }
};
