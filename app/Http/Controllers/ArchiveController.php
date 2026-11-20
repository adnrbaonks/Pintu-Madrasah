<?php

namespace App\Http\Controllers;

use App\Jobs\UploadToGoogleDriveJob;
use App\Models\PpdbDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentMutation;
use Illuminate\Support\Facades\Storage;

class ArchiveController extends Controller
{
    /**
     * Show private files directly from storage.
     */
    public function show($type, $filename)
    {
        $path = "archives/{$type}/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'File not found or has been uploaded to Google Drive.');
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Retry uploading a document.
     */
    public function retry($type, $id)
    {
        // $type can be: ppdb, mutation, student_document, photo
        if ($type === 'ppdb') {
            $model = PpdbDocument::findOrFail($id);
            $model->update(['status' => 'pending']);
            UploadToGoogleDriveJob::dispatch('ppdb', $model->id);
        } elseif ($type === 'mutation') {
            $model = StudentMutation::findOrFail($id);
            $model->update(['document_status' => 'pending']);
            UploadToGoogleDriveJob::dispatch('mutation', $model->id);
        } elseif ($type === 'student_document') {
            $model = StudentDocument::findOrFail($id);
            $model->update(['status' => 'pending']);
            UploadToGoogleDriveJob::dispatch('student_document', $model->id);
        } elseif ($type === 'photo') {
            $model = Student::findOrFail($id);
            $model->update(['photo_upload_status' => 'pending']);
            UploadToGoogleDriveJob::dispatch('photo', $model->id);
        } else {
            abort(400, 'Invalid document type');
        }

        return redirect()->back()->with('success', 'Upload ulang sedang diproses di latar belakang.');
    }
}
