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
        Schema::create('grading_formulas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('academic_year_id')->unique()->constrained('academic_years')->cascadeOnDelete();

            // Bobot komponen dalam persentase (total 100)
            $table->decimal('weight_p1', 5, 2)->default(20.00)->comment('Bobot Formatif 1 (%)');
            $table->decimal('weight_p2', 5, 2)->default(20.00)->comment('Bobot Formatif 2 (%)');
            $table->decimal('weight_sts', 5, 2)->default(30.00)->comment('Bobot Sumatif Tengah Semester (%)');
            $table->decimal('weight_sas', 5, 2)->default(30.00)->comment('Bobot Sumatif Akhir Semester (%)');

            // Ambang batas predikat
            $table->decimal('threshold_a', 5, 2)->default(90.00)->comment('Batas bawah nilai A');
            $table->decimal('threshold_b', 5, 2)->default(80.00)->comment('Batas bawah nilai B');
            $table->decimal('threshold_c', 5, 2)->default(70.00)->comment('Batas bawah nilai C');

            $table->string('formula_type', 30)->default('weighted_average')->comment('weighted_average | equal_average');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grading_formulas');
    }
};
