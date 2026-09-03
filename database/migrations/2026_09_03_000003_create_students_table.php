<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Nomor Identitas ───────────────────────────────────────
            $table->char('nisn', 10)->unique()->nullable()
                  ->comment('10 digit numerik — wajib untuk Verval PD');
            $table->char('nik', 16)->unique()->nullable()
                  ->comment('16 digit NIK sesuai KTP/KK');
            $table->string('nis_local')->nullable()->comment('NIS lokal madrasah');
            $table->char('kk_number', 16)->nullable()->comment('Nomor Kartu Keluarga');

            // ── Identitas Diri ────────────────────────────────────────
            $table->string('name')->comment('Nama lengkap sesuai Akta Kelahiran');
            $table->string('nickname')->nullable()->comment('Nama panggilan');
            $table->string('gender', 1)->comment('L | P');
            $table->string('birth_place');
            $table->date('birth_date');
            $table->string('religion')->default('Islam');
            $table->string('citizenship')->default('WNI');
            $table->unsignedTinyInteger('child_order')->nullable()->comment('Anak ke-');
            $table->unsignedTinyInteger('siblings_count')->nullable()->comment('Jumlah saudara kandung');
            $table->string('living_status')->nullable()->comment('Bersama Orangtua | Wali | Kos | Panti');

            // ── Alamat ────────────────────────────────────────────────
            $table->text('address')->nullable();
            $table->string('rt', 4)->nullable();
            $table->string('rw', 4)->nullable();
            $table->string('village')->nullable()->comment('Kelurahan/Desa');
            $table->string('district')->nullable()->comment('Kecamatan');
            $table->string('regency')->nullable()->comment('Kabupaten/Kota');
            $table->string('postal_code', 10)->nullable();
            $table->string('distance_to_school')->nullable()->comment('< 1 km | 1–3 km | > 3 km');
            $table->string('transportation')->nullable()->comment('Jalan kaki | Sepeda | Motor | Angkot | dll.');

            // ── Kesehatan ─────────────────────────────────────────────
            $table->string('blood_type', 5)->nullable()->comment('A | B | AB | O');
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->text('medical_history')->nullable();

            // ── Asal Sekolah ──────────────────────────────────────────
            $table->string('prev_school_name')->nullable()->comment('Nama SD/MI asal');
            $table->string('prev_exam_number')->nullable()->comment('No. Ijazah/SKHU');
            $table->year('graduation_year')->nullable();

            // ── Data Ayah ─────────────────────────────────────────────
            $table->string('father_name')->nullable();
            $table->char('father_nik', 16)->nullable();
            $table->string('father_job')->nullable();
            $table->unsignedInteger('father_income')->nullable()->comment('Pendapatan/bulan (Rp)');
            $table->string('father_phone')->nullable();

            // ── Data Ibu (kunci Verval PD) ────────────────────────────
            $table->string('mother_name')->nullable()
                  ->comment('Nama ibu kandung MURNI tanpa gelar — KUNCI Verval PD');
            $table->char('mother_nik', 16)->nullable();
            $table->string('mother_job')->nullable();
            $table->unsignedInteger('mother_income')->nullable();
            $table->string('mother_phone')->nullable();

            // ── Data Wali ─────────────────────────────────────────────
            $table->string('guardian_name')->nullable();
            $table->char('guardian_nik', 16)->nullable();
            $table->string('guardian_job')->nullable();
            $table->string('guardian_phone')->nullable();

            // ── Bantuan Sosial ────────────────────────────────────────
            $table->string('kip_number')->nullable()->comment('Nomor Kartu Indonesia Pintar');
            $table->boolean('pip_eligible')->default(false)->comment('Penerima Program Indonesia Pintar');
            $table->boolean('pkh_eligible')->default(false)->comment('Penerima PKH');
            $table->boolean('kks_eligible')->default(false)->comment('Penerima KKS');

            // ── Status & Verifikasi ───────────────────────────────────
            $table->string('status')->default('active')
                  ->comment('active | graduated | mutated_out | dropped');
            $table->boolean('is_verified_by_ai')->default(false)
                  ->comment('Dokumen telah diverifikasi Gemini AI');
            $table->boolean('is_locked')->default(false)
                  ->comment('Field identitas terkunci — tidak bisa di-overwrite data EMIS');

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────────
            $table->index('nisn');
            $table->index('nik');
            $table->index('status');
            $table->index('is_verified_by_ai');
            $table->index('is_locked');
            $table->index('name');
        });

        // ── Riwayat Historis Siswa per Semester ───────────────────────
        Schema::create('student_academic_cohorts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->string('semester')->comment('Ganjil | Genap');
            $table->foreignUuid('rombel_id')->constrained('rombels');
            $table->unsignedSmallInteger('attendance_number')->nullable()->comment('Nomor absen');
            $table->string('status')->default('active')
                  ->comment('active | graduated | mutated_out | dropped');
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id', 'semester'], 'cohort_unique');
            $table->index(['academic_year_id', 'rombel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_cohorts');
        Schema::dropIfExists('students');
    }
};
