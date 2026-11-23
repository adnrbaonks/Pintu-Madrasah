<?php

namespace App\Http\Controllers;

use App\Jobs\UploadToGoogleDriveJob;
use App\Models\ActivityLog;
use App\Models\StaffDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffSelfController extends Controller
{
    public function profile(Request $request): Response
    {
        $user = $request->user();
        $staff = $user->staff()?->with([
            'documents' => fn ($q) => $q->latest(),
            'positionAssignments' => fn ($q) => $q
                ->with(['position:id,code,name,level,is_structural', 'unit:id,code,name,type'])
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('is_primary')
                ->orderByDesc('valid_from'),
            'changeRequests' => fn ($q) => $q->withCount('items')->latest()->limit(5),
        ])->first();

        return Inertia::render('StaffSelf/Profile', [
            'staff' => $staff,
            'ownership' => config('data_ownership.staff', []),
            'notifications' => $user->notifications()->latest()->limit(10)->get(),
        ]);
    }

    public function documents(Request $request): Response
    {
        $user = $request->user();
        $staff = $user->staff()?->with([
            'documents' => fn ($q) => $q->latest(),
        ])->first();

        return Inertia::render('StaffSelf/Documents', [
            'staff' => $staff,
            'documents' => $staff?->documents ?? [],
        ]);
    }

    public function uploadDocument(Request $request): RedirectResponse
    {
        $user = $request->user();
        $staff = $user->staff;

        if (! $staff) {
            return back()->withErrors(['staff' => 'Akun Anda belum terhubung dengan profil pegawai GTK.']);
        }

        $validated = $request->validate([
            'document_type' => [
                'required',
                Rule::in(['sk_pengangkatan', 'ijazah', 'sertifikat_pendidik', 'ktp', 'kk', 'lainnya']),
            ],
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $file = $request->file('file');
        $documentId = (string) Str::uuid();
        $extension = $file->getClientOriginalExtension();
        $relativePath = "archives/staff_document/{$documentId}.{$extension}";

        Storage::disk('local')->put($relativePath, file_get_contents($file->getRealPath()));

        $document = StaffDocument::create([
            'id' => $documentId,
            'staff_id' => $staff->id,
            'document_type' => $validated['document_type'],
            'title' => $validated['title'],
            'file_path' => $relativePath,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'status' => 'uploaded',
            'uploaded_by' => $user->id,
        ]);

        if (env('GOOGLE_DRIVE_FOLDER_ID') || env('GDRIVE_FOLDER_ID')) {
            $document->update(['status' => 'pending']);
            UploadToGoogleDriveJob::dispatch('staff_document', $document->id);
        }

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'uploaded_self_staff_document',
            'model_type' => StaffDocument::class,
            'model_id' => $document->id,
            'before_payload' => null,
            'after_payload' => [
                'staff_id' => $staff->id,
                'document_type' => $document->document_type,
                'title' => $document->title,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Dokumen kepegawaian berhasil diunggah.');
    }

    public function destroyDocument(Request $request, StaffDocument $document): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staff_id || $document->staff_id !== $user->staff_id) {
            abort(403, 'Anda hanya dapat menghapus dokumen milik Anda sendiri.');
        }

        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        $docId = $document->id;
        $before = $document->only(['staff_id', 'document_type', 'title']);
        $document->delete();

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'deleted_self_staff_document',
            'model_type' => StaffDocument::class,
            'model_id' => $docId,
            'before_payload' => $before,
            'after_payload' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Dokumen berhasil dihapus.');
    }
}
