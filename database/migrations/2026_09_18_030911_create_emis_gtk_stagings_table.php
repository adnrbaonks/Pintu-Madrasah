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
        Schema::create('emis_gtk_stagings', function (Blueprint $table) {
            $table->id();
            $table->string('emis_gtk_id')->nullable();
            $table->string('nip', 20)->nullable();
            $table->string('nik', 20)->nullable();
            $table->string('npk', 20)->nullable();
            $table->string('nuptk', 20)->nullable();
            $table->string('full_name_with_titles')->nullable();
            $table->string('first_title', 50)->nullable();
            $table->string('last_title', 50)->nullable();
            $table->enum('gender', ['L', 'P'])->nullable();
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('employment_status', 50)->nullable(); // PNS, PPPK, Honorer, dll
            $table->string('civil_rank', 50)->nullable(); // Golongan (misal: III/a)
            $table->date('civil_rank_tmt')->nullable(); // TMT Golongan
            $table->boolean('tpg_status')->default(false); // Validasi TPG
            $table->string('functional_position', 100)->nullable(); // Jabatan Fungsional
            $table->string('highest_education', 50)->nullable(); // Pendidikan Terakhir (S1, S2)
            $table->string('university_name')->nullable();
            $table->string('study_program')->nullable();
            $table->integer('graduation_year')->nullable();

            $table->json('raw_data')->nullable(); // Data mentah dari JSON EMIS
            $table->enum('status', ['pending', 'committed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emis_gtk_stagings');
    }
};
