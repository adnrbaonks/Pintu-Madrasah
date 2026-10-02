<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('ppdb_registrations');

        Schema::create('ppdb_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ppdb_period_id')->nullable();
            $table->uuid('academic_year_id')->nullable();
            $table->string('registration_number')->unique();
            $table->string('status')->default('registered'); // registered, re_enrolled, verified, placed

            // Biodata Siswa
            $table->string('name');
            $table->string('nisn')->nullable()->index();
            $table->string('nik')->nullable()->index();
            $table->string('gender', 1)->default('L'); // L / P
            $table->string('birth_place');
            $table->date('birth_date');
            $table->string('religion')->default('Islam');

            // Alamat & Kontak
            $table->text('address')->nullable();
            $table->string('rt', 5)->nullable();
            $table->string('rw', 5)->nullable();
            $table->string('village')->nullable();
            $table->string('district')->nullable();
            $table->string('regency')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone_number')->nullable();

            // Sekolah Asal
            $table->string('prev_school_name')->nullable();
            $table->string('prev_school_npsn')->nullable();
            $table->string('graduation_year', 4)->nullable();

            // Data Ayah
            $table->string('father_name')->nullable();
            $table->string('father_nik')->nullable();
            $table->string('father_job')->nullable();
            $table->unsignedBigInteger('father_income')->nullable();
            $table->string('father_phone')->nullable();

            // Data Ibu
            $table->string('mother_name')->nullable();
            $table->string('mother_nik')->nullable();
            $table->string('mother_job')->nullable();
            $table->unsignedBigInteger('mother_income')->nullable();
            $table->string('mother_phone')->nullable();

            // Data Wali
            $table->string('guardian_name')->nullable();
            $table->string('guardian_nik')->nullable();
            $table->string('guardian_job')->nullable();
            $table->string('guardian_phone')->nullable();

            // Meta & Konversi
            $table->uuid('registered_by')->nullable();
            $table->uuid('converted_student_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('ppdb_period_id')
                ->references('id')
                ->on('ppdb_periods')
                ->nullOnDelete();

            $table->foreign('academic_year_id')
                ->references('id')
                ->on('academic_years')
                ->nullOnDelete();

            $table->foreign('converted_student_id')
                ->references('id')
                ->on('students')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ppdb_registrations');
    }
};
