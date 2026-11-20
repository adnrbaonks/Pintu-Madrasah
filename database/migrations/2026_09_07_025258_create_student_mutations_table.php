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
        Schema::create('student_mutations', function (Blueprint $table) {
            $table->id();
            // students.id adalah UUID; foreignId() akan gagal di MySQL.
            $table->foreignUuid('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('emis_student_id')->nullable();
            $table->string('nisn')->nullable();
            $table->string('name')->nullable();
            $table->enum('type', ['in', 'out'])->index();
            $table->date('mutation_date')->nullable();
            $table->string('reason')->nullable();
            $table->string('origin_school')->nullable();
            $table->string('destination_school')->nullable();
            $table->string('status')->default('completed');
            $table->json('raw_data')->nullable(); // Store raw emis data just in case
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_mutations');
    }
};
