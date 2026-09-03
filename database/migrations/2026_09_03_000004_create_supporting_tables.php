<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Arsip Dokumen Siswa (pointer ke Google Drive) ─────────────
        Schema::create('student_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('document_type')
                  ->comment('akta_kelahiran | kartu_keluarga | ijazah_sd | foto | lainnya');
            $table->string('gdrive_file_id')->nullable()->comment('Google Drive File ID');
            $table->text('gdrive_view_url')->nullable()->comment('URL preview drive.google.com');
            $table->unsignedInteger('file_size')->nullable()->comment('Ukuran file dalam bytes');
            $table->boolean('is_verified_ai')->default(false)
                  ->comment('Sudah diverifikasi Gemini AI');
            $table->json('ai_verification_result')->nullable()
                  ->comment('Hasil ekstraksi & skor kecocokan dari Gemini');
            $table->timestamps();

            $table->index(['student_id', 'document_type']);
            $table->index('is_verified_ai');
        });

        // ── Audit Trail Aktivitas ─────────────────────────────────────
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->comment('Siapa yang melakukan aksi');
            $table->string('action')->comment('created | updated | deleted | synced | verified');
            $table->string('model_type')->comment('App\Models\Student | App\Models\Staff');
            $table->uuid('model_id')->nullable()->comment('ID record yang dimodifikasi');
            $table->json('before_payload')->nullable()->comment('State data sebelum perubahan');
            $table->json('after_payload')->nullable()->comment('State data sesudah perubahan');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['model_type', 'model_id']);
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });

        // ── EMIS Token Cache (staging sementara di DB) ────────────────
        // (Di production menggunakan Redis, ini fallback untuk dev SQLite)
        Schema::create('emis_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('source')->comment('emis_siswa | emis_gtk');
            $table->text('token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('source');
        });

        // ── EMIS Data Staging (antrian sinkronisasi) ──────────────────
        Schema::create('emis_staging', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source')->comment('emis_siswa | emis_gtk');
            $table->string('nisn')->nullable();
            $table->json('raw_data')->comment('Data mentah dari EMIS sebelum di-parse');
            $table->string('sync_status')->default('pending')
                  ->comment('pending | processing | synced | failed | skipped');
            $table->text('sync_notes')->nullable()->comment('Catatan hasil sinkronisasi / error');
            $table->timestamps();

            $table->index(['source', 'sync_status']);
            $table->index('nisn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emis_staging');
        Schema::dropIfExists('emis_tokens');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('student_documents');
    }
};
