<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // ── Identitas Kepegawaian ──────────────────────────────────
            $table->string('nip', 18)->nullable()->unique()->comment('Nomor Induk Pegawai (ASN/PPPK)');
            $table->string('nik', 16)->unique()->comment('NIK KTP — wajib semua GTK');
            $table->string('npk')->nullable()->comment('Nomor Pokok Karyawan (Honorer yayasan)');
            $table->string('nuptk')->nullable()->unique()->comment('Nomor Unik PTK Kemdikbud');

            // ── Nama ──────────────────────────────────────────────────
            $table->string('full_name_with_titles')->comment('Nama lengkap dengan gelar akademik');
            $table->string('first_title')->nullable()->comment('Gelar depan: Drs., S.Pd., M.Pd.');
            $table->string('last_title')->nullable()->comment('Gelar belakang: S.Pd., M.Pd., Ph.D.');

            // ── Data Pribadi ──────────────────────────────────────────
            $table->string('gender', 1)->comment('L | P');
            $table->string('birth_place');
            $table->date('birth_date');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // ── Kepegawaian ───────────────────────────────────────────
            $table->string('employment_status')
                ->comment('pns | pppk | honorer | gtt | ptt');
            $table->string('civil_rank')->nullable()
                ->comment('Golongan Ruang: III/a, III/b, IV/a, dll.');
            $table->date('civil_rank_tmt')->nullable()->comment('TMT golongan terakhir');
            $table->boolean('tpg_status')->default(false)
                ->comment('Kelayakan pencairan Tunjangan Profesi Guru');

            // ── Jabatan & Fungsi ──────────────────────────────────────
            $table->string('functional_position')->nullable()
                ->comment('Guru Mapel | Wali Kelas | Kepala TU | Staf Administrasi');

            // ── Pendidikan ────────────────────────────────────────────
            $table->string('highest_education')->nullable()->comment('S1 | S2 | S3 | D3 | D4');
            $table->string('university_name')->nullable();
            $table->string('study_program')->nullable();
            $table->year('graduation_year')->nullable();

            // ── Status ────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────────
            $table->index('employment_status');
            $table->index('is_active');
            $table->index('tpg_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
