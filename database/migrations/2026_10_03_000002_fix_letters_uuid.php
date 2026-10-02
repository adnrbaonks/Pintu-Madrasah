<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // letters kosong: recreate dengan created_by UUID + tanpa academic_year_id
        Schema::dropIfExists('letters');

        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('number');
            $table->string('number_seq')->nullable();
            $table->string('subject');
            $table->string('category', 50)->default('umum');
            $table->date('letter_date');
            $table->date('received_date')->nullable();
            $table->string('from_to');
            $table->text('description')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('disposisi')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'letter_date']);
            $table->index(['type', 'number']);
        });
    }

    public function down(): void
    {
        // tidak reversibel penuh; kolom academic_year_id yang dibuang memang tidak dipakai
    }
};
