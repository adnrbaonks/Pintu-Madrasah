<?php

namespace App\Jobs;

use App\Models\Student;
use App\Models\StudentDocument;
use App\Services\FileCompressionService;
use App\Services\GeminiVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessStudentDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120; // 2 menit

    public function __construct(
        public string $documentId,
        public string $filePath,
        public string $mimeType,
        public string $originalName,
        public int $fileSize
    ) {
        $this->onQueue('emis');
    }

    public function handle(GeminiVerificationService $gemini): void
    {
        Log::info('[JOB] Memulai pemrosesan dokumen siswa', ['document_id' => $this->documentId]);

        $document = StudentDocument::findOrFail($this->documentId);
        $student = Student::findOrFail($document->student_id);
        $fullPath = storage_path('app/private/'.$this->filePath);

        if (! file_exists($fullPath)) {
            Log::error('[JOB] File tidak ditemukan', ['path' => $fullPath]);
            $document->update(['status' => 'failed', 'ai_verification_result' => ['error' => 'File tidak ditemukan']]);

            return;
        }

        // 1. Kompres file (jika ukuran melebihi 1MB)
        $compressionService = app(FileCompressionService::class);
        $fullPath = $compressionService->compressIfNeeded($fullPath, $this->mimeType, pathinfo($this->originalName, PATHINFO_EXTENSION));

        // Perbarui file size setelah kompresi
        $this->fileSize = filesize($fullPath);

        // 2. Pindahkan file dari private/temp_docs ke private/archives
        $extension = pathinfo($this->originalName, PATHINFO_EXTENSION);
        $archivePath = 'archives/student_document/'.$document->id.'.'.$extension;
        Storage::disk('local')->put($archivePath, file_get_contents($fullPath));

        // 3. Verifikasi dengan Gemini AI
        $currentData = $student->only(['nik', 'nisn', 'name', 'birth_place', 'birth_date', 'mother_name', 'father_name', 'prev_school_name']);
        $aiResult = $gemini->verifyDocument($fullPath, $this->mimeType, $currentData);

        // 4. Hapus file sementara lokal di temp_docs
        @unlink($fullPath);

        $isVerified = false;
        if (! $aiResult) {
            throw new \Exception('Gagal memproses dokumen dengan AI Gemini. Hasil kosong atau terjadi error API.');
        }

        $updates = [];

        // 4.1. Auto-fill data kosong dari hasil ekstraksi AI
        $fillableFields = ['nik', 'nisn', 'birth_place', 'birth_date', 'mother_name', 'father_name', 'prev_school_name'];
        foreach ($fillableFields as $field) {
            // Jika data di database kosong/null, dan AI berhasil menemukannya, maka ambil
            if (empty($student->$field) && ! empty($aiResult[$field]) && $aiResult[$field] !== 'unknown' && $aiResult[$field] !== '-') {
                $updates[$field] = $aiResult[$field];
            }
        }

        // 4.2. Kunci otomatis jika match_score tinggi
        if (isset($aiResult['match_score']) && $aiResult['match_score'] >= 80) {
            $isVerified = true;
            $updates['is_verified_by_ai'] = true;
            $updates['is_locked'] = true;
            Log::info('[JOB] Identitas siswa dikunci karena verifikasi AI tinggi', ['student_id' => $student->id]);
        }

        if (! empty($updates)) {
            $student->update($updates);
            Log::info('[JOB] Data siswa berhasil dilengkapi oleh AI', ['student_id' => $student->id, 'fields_updated' => array_keys($updates)]);
        }

        $document->update([
            'gdrive_file_id' => null, // Akan diisi oleh GDrive job
            'gdrive_view_url' => null,
            'file_size' => $this->fileSize,
            'is_verified_ai' => $isVerified,
            'ai_verification_result' => $aiResult,
            'status' => 'pending', // Menunggu GDrive upload
        ]);

        // 5. Dispatch background job untuk upload ke GDrive
        UploadToGoogleDriveJob::dispatch('student_document', $document->id);

        Log::info('[JOB] Dokumen siswa berhasil diverifikasi AI secara lokal dan diteruskan ke GDrive job', [
            'student_id' => $student->id,
            'verified' => $isVerified,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[JOB] Proses dokumen siswa gagal', [
            'document_id' => $this->documentId,
            'exception' => $exception->getMessage(),
        ]);

        $document = StudentDocument::find($this->documentId);
        if ($document) {
            $document->update([
                'status' => 'failed',
                'ai_verification_result' => ['error' => $exception->getMessage()],
            ]);
        }
    }
}
