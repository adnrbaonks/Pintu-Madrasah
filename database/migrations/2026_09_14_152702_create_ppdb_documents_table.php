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
        Schema::create('ppdb_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ppdb_registration_id');
            $table->string('document_type'); // ijazah, kartu_keluarga, akta_kelahiran, ktp_ortu, berkas_khusus, dll
            $table->string('document_name')->nullable();
            $table->string('file_path');
            $table->unsignedInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->json('ai_extracted_data')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('ppdb_registration_id')
                ->references('id')
                ->on('ppdb_registrations')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ppdb_documents');
    }
};
