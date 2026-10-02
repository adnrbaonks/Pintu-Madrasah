<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Jobs\ProcessStudentDocumentJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StudentDocumentController extends Controller
{
    /**
     * Upload a new document for a student.
     */
    public function upload(Request $request, Student $student)
    {
        \Log::info('[UPLOAD] Request masuk untuk siswa: ' . $student->id, $request->all());

        try {
            $request->validate([
                'document_type' => 'required|in:akta_kelahiran,kartu_keluarga,ijazah_sd,foto,lainnya',
                'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // Max 5MB
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('[UPLOAD] Validasi gagal: ', $e->errors());
            throw $e;
        }

        $file = $request->file('file');
        \Log::info('[UPLOAD] Validasi sukses. File: ' . $file->getClientOriginalName() . ' | Size: ' . $file->getSize());
        
        // Simpan file sementara di storage lokal (private)
        // Proses upload ke GDrive & verifikasi AI akan dilakukan oleh Queue Worker
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();
        
        $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('temp_docs', $filename);

        // 1. Buat atau update record di database dengan status 'processing'
        $document = \App\Models\StudentDocument::updateOrCreate(
            [
                'student_id' => $student->id,
                'document_type' => $request->input('document_type'),
            ],
            [
                'status' => 'processing',
                'gdrive_file_id' => null,
                'gdrive_view_url' => null,
                'ai_verification_result' => null,
                'is_verified_ai' => false,
            ]
        );

        // Dispatch background job
        ProcessStudentDocumentJob::dispatch(
            $document->id, // Pass document ID instead of just student ID
            $path,
            $mimeType,
            $originalName,
            $fileSize
        );

        return response()->json([
            'message' => 'Dokumen berhasil diunggah dan sedang diproses.',
            'document_id' => $document->id,
        ]);
    }
}
