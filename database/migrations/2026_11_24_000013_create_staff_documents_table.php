<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('document_type')->comment('sk_pengangkatan | ijazah | sertifikat_pendidik | ktp | kk | lainnya');
            $table->string('title');
            $table->string('file_path')->nullable()->comment('Path relatif pada disk private');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('file_size')->default(0);
            $table->string('gdrive_file_id')->nullable();
            $table->string('gdrive_view_url')->nullable();
            $table->string('status')->default('uploaded')->comment('pending | uploading | uploaded | failed');
            $table->unsignedTinyInteger('upload_retries')->default(0);
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'document_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_documents');
    }
};
