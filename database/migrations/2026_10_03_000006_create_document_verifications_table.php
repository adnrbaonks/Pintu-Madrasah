<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token', 64)->unique()->comment('Kode publik di QR, cth /v/xxxx');
            $table->string('document_type', 30)->comment('biodata | surat_keluar');
            $table->nullableMorphs('verifiable');
            $table->json('snapshot')->comment('Data ringkas saat dokumen dicetak');
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['document_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
    }
};
