<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bk_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->date('record_date');
            $table->string('type', 20)->comment('violation | habit | achievement | home_visit | summon');
            $table->string('category', 100)->comment('Terlambat | Bolos | Seragam | ... / P5-PPRA dimensi');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('points')->default(0)->comment('negatif = pelanggaran, positif = pembinaan/prestasi');
            $table->text('follow_up')->nullable()->comment('tindak lanjut: teguran, home visit, pemanggilan ortu');
            $table->string('status', 20)->default('open')->comment('open | followup | closed');
            $table->foreignUuid('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'academic_year_id']);
            $table->index(['type', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_records');
    }
};
