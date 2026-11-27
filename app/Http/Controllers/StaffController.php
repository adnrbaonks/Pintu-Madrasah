<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStaffRequest;
use App\Jobs\UploadToGoogleDriveJob;
use App\Models\ActivityLog;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Services\DataOwnershipService;
use App\Services\StaffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(
        private StaffService $staffService,
        private DataOwnershipService $dataOwnershipService,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $query = Staff::query()
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('full_name_with_titles', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->orderBy('is_active', 'desc')
            ->orderBy('full_name_with_titles', 'asc');

        $staff = $query->paginate(15)->withQueryString();

        return Inertia::render('Gtk/Index', [
            'staff' => $staff,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function show(Staff $staff): Response
    {
        $staff->load([
            'user:id,staff_id,username,role,is_active',
            'homeroomRombels.academicYear',
            'homeroomRombels.schoolClass',
            'documents' => fn ($query) => $query->latest(),
            'positionAssignments' => fn ($query) => $query
                ->with(['position:id,code,name,level,is_structural', 'unit:id,code,name,type'])
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('is_primary')
                ->orderByDesc('valid_from'),
        ]);

        $ownership = config('data_ownership.staff', []);

        return Inertia::render('Gtk/Show', [
            'staff' => $staff,
            'ownership' => $ownership,
        ]);
    }

    public function edit(Staff $staff): Response
    {
        return Inertia::render('Gtk/Edit', [
            'staff' => $staff,
            'ownership' => config('data_ownership.staff', []),
            'editableFields' => $this->dataOwnershipService->getEditableFields('staff'),
            'readOnlyFields' => $this->dataOwnershipService->getReadOnlyFields('staff'),
        ]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): RedirectResponse
    {
        $protectedFields = array_unique(array_merge(
            $this->dataOwnershipService->getApprovalRequiredFields('staff'),
            $this->dataOwnershipService->getReadOnlyFields('staff'),
        ));

        $attemptedProtectedFields = [];
        foreach ($protectedFields as $field) {
            if ($request->exists($field) && (string) ($request->input($field) ?? '') !== (string) ($staff->getAttribute($field) ?? '')) {
                $attemptedProtectedFields[] = $field;
            }
        }

        $allowedAttributes = $this->dataOwnershipService->filterAllowedAttributes('staff', $request->validated());

        if ($attemptedProtectedFields !== [] && $allowedAttributes === []) {
            return redirect()->back()->withErrors([
                'approval_required' => 'Perubahan atribut substantif GTK ('.implode(', ', $attemptedProtectedFields).') wajib melalui alur pengajuan berjenjang (GTK -> Kaur TU -> Kepala Madrasah).',
            ]);
        }

        $this->staffService->update($staff, $allowedAttributes, auth()->id());

        return redirect()->route('gtk.show', $staff->id)
            ->with('success', 'Data pegawai GTK berhasil diperbarui.');
    }

    public function uploadDocument(Request $request, Staff $staff): RedirectResponse
    {
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
            'uploaded_by' => auth()->id(),
        ]);

        if (env('GOOGLE_DRIVE_FOLDER_ID') || env('GDRIVE_FOLDER_ID')) {
            $document->update(['status' => 'pending']);
            UploadToGoogleDriveJob::dispatch('staff_document', $document->id);
        }

        ActivityLog::record('uploaded_document', $staff, null, [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
            'title' => $document->title,
        ]);

        return redirect()->route('gtk.show', $staff->id)
            ->with('success', 'Dokumen arsip GTK berhasil diunggah.');
    }

    public function destroyDocument(Staff $staff, StaffDocument $document): RedirectResponse
    {
        if ($document->staff_id !== $staff->id) {
            abort(404);
        }

        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        $before = [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
            'title' => $document->title,
        ];

        $document->delete();

        ActivityLog::record('deleted_document', $staff, $before, null);

        return redirect()->route('gtk.show', $staff->id)
            ->with('success', 'Dokumen arsip GTK berhasil dihapus.');
    }
}
