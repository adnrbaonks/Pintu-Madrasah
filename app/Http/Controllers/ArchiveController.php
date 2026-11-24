<?php

namespace App\Http\Controllers;

use App\Jobs\UploadToGoogleDriveJob;
use App\Models\AcademicYear;
use App\Models\Archive;
use App\Models\ArchiveCategory;
use App\Models\OrganizationUnit;
use App\Models\PpdbDocument;
use App\Models\StaffChangeAttachment;
use App\Models\StaffDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentMutation;
use App\Services\ArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArchiveController extends Controller
{
    /**
     * Permission granular per jenis dokumen privat.
     *
     * @var array<string, string>
     */
    private const DOCUMENT_VIEW_PERMISSIONS = [
        'student_document' => 'view_student_documents',
        'photo' => 'view_student_documents',
        'ppdb' => 'view_ppdb_documents',
        'mutation' => 'view_mutation_documents',
        'staff_document' => 'view_staff',
    ];

    public function __construct(
        private ArchiveService $archiveService,
    ) {}

    /**
     * Daftar Arsip Digital Madrasah (WP8.1).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->archiveService->ensureDefaultCategories();

        $query = Archive::query()
            ->with([
                'category:id,code,name',
                'organizationUnit:id,code,name',
                'academicYear:id,year,semester',
                'latestVersion:id,archive_id,version_number,original_name,file_size,mime_type,created_at',
                'creator:id,name',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('archive_number', 'like', "%{$search}%")
                    ->orWhere('classification_code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('archive_category_id', (string) $request->query('category_id'));
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', (string) $request->query('visibility'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $allMatches = $query->limit(200)->get();
        $visibleArchives = $allMatches
            ->filter(fn (Archive $archive): bool => $this->archiveService->canView($user, $archive))
            ->values();

        return Inertia::render('Archives/Index', [
            'archives' => $visibleArchives,
            'categories' => ArchiveCategory::where('is_active', true)->orderBy('name')->get(),
            'units' => OrganizationUnit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'academicYears' => AcademicYear::orderByDesc('year')->get(['id', 'year', 'semester', 'is_active']),
            'canManage' => $this->archiveService->canManage($user),
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'category_id' => (string) $request->query('category_id', ''),
                'visibility' => (string) $request->query('visibility', ''),
                'status' => (string) $request->query('status', ''),
            ],
            'stats' => [
                'total' => $visibleArchives->count(),
                'private_count' => $visibleArchives->where('visibility', Archive::VISIBILITY_PRIVATE)->count(),
                'expired_count' => $visibleArchives->where('status', Archive::STATUS_EXPIRED)->count(),
            ],
        ]);
    }

    /**
     * Simpan Arsip Digital baru beserta file Versi 1.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $this->archiveService->canManage($user)) {
            abort(403, 'Anda tidak memiliki izin untuk mengunggah arsip.');
        }

        $validated = $request->validate([
            'archive_category_id' => 'required|uuid|exists:archive_categories,id',
            'organization_unit_id' => 'nullable|uuid|exists:org_units,id',
            'academic_year_id' => 'nullable|uuid|exists:academic_years,id',
            'archive_number' => 'nullable|string|max:100|unique:archives,archive_number',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'classification_code' => 'nullable|string|max:50',
            'visibility' => 'required|in:private,internal,role_restricted',
            'allowed_roles' => 'nullable|array',
            'allowed_roles.*' => 'string|in:admin,kepala_madrasah,kaur_tu,staf,operator,guru,pegawai',
            'document_date' => 'nullable|date',
            'retention_until' => 'nullable|date',
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip',
        ]);

        $archive = $this->archiveService->createArchive(
            data: $validated,
            file: $request->file('file'),
            actor: $user,
            request: $request,
        );

        return redirect()
            ->route('digital-archives.show', $archive->id)
            ->with('success', "Arsip {$archive->archive_number} berhasil disimpan (Versi 1).");
    }

    /**
     * Detail Arsip Digital, daftar versi, signed URL, dan log audit akses.
     */
    public function detail(Request $request, Archive $archive): Response
    {
        $user = $request->user();
        if (! $this->archiveService->canView($user, $archive)) {
            $this->archiveService->logAccess(
                archive: $archive,
                action: 'access_denied',
                actor: $user,
                request: $request,
            );
            abort(403, 'Anda tidak memiliki hak akses untuk melihat arsip ini.');
        }

        $archive->load([
            'category',
            'organizationUnit:id,code,name',
            'academicYear:id,year,semester',
            'creator:id,name',
            'ownerUser:id,name',
            'versions.uploader:id,name',
            'accessLogs' => fn ($q) => $q->with('user:id,name,role')->limit(50),
        ]);

        $this->archiveService->logAccess(
            archive: $archive,
            action: 'viewed',
            actor: $user,
            version: $archive->latestVersion,
            request: $request,
        );

        $signedUrls = [];
        foreach ($archive->versions as $ver) {
            $signedUrls[$ver->version_number] = $this->archiveService->temporaryDownloadUrl($archive, $ver);
        }

        return Inertia::render('Archives/Show', [
            'archive' => $archive,
            'signedUrls' => $signedUrls,
            'canManage' => $this->archiveService->canManage($user, $archive),
            'categories' => ArchiveCategory::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'units' => OrganizationUnit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Perbarui metadata arsip.
     */
    public function update(Request $request, Archive $archive): RedirectResponse
    {
        $user = $request->user();
        if (! $this->archiveService->canManage($user, $archive)) {
            abort(403, 'Anda tidak memiliki izin untuk mengubah metadata arsip ini.');
        }

        $validated = $request->validate([
            'archive_category_id' => 'required|uuid|exists:archive_categories,id',
            'organization_unit_id' => 'nullable|uuid|exists:org_units,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'classification_code' => 'nullable|string|max:50',
            'visibility' => 'required|in:private,internal,role_restricted',
            'allowed_roles' => 'nullable|array',
            'allowed_roles.*' => 'string|in:admin,kepala_madrasah,kaur_tu,staf,operator,guru,pegawai',
            'document_date' => 'nullable|date',
            'retention_until' => 'nullable|date',
            'status' => 'required|in:active,expired,disposed',
        ]);

        $archive->update($validated);

        $this->archiveService->logAccess(
            archive: $archive,
            action: 'metadata_updated',
            actor: $user,
            request: $request,
        );

        return redirect()->back()->with('success', 'Metadata arsip berhasil diperbarui.');
    }

    /**
     * Unggah versi revisi baru ke arsip.
     */
    public function storeVersion(Request $request, Archive $archive): RedirectResponse
    {
        $user = $request->user();
        if (! $this->archiveService->canManage($user, $archive)) {
            abort(403, 'Anda tidak memiliki izin untuk mengunggah versi baru pada arsip ini.');
        }

        $validated = $request->validate([
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip',
            'change_notes' => 'nullable|string|max:500',
        ]);

        $version = $this->archiveService->uploadNewVersion(
            archive: $archive,
            file: $request->file('file'),
            actor: $user,
            changeNotes: $validated['change_notes'] ?? null,
            request: $request,
        );

        return redirect()->back()->with('success', "Versi v{$version->version_number} berhasil diunggah.");
    }

    /**
     * Unduh file arsip melalui Temporary Signed URL + pemeriksaan otorisasi & pencatatan audit.
     */
    public function downloadSigned(Request $request, Archive $archive, int $version): BinaryFileResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Tautan unduhan arsip sudah kedaluwarsa atau tidak valid.');
        }

        $user = $request->user();
        if (! $user || ! $this->archiveService->canView($user, $archive)) {
            abort(403, 'Anda tidak memiliki hak akses untuk mengunduh arsip ini.');
        }

        $archiveVersion = $archive->versions()->where('version_number', $version)->firstOrFail();

        if (! Storage::disk($archiveVersion->storage_disk)->exists($archiveVersion->file_path)) {
            abort(404, 'Berkas fisik arsip tidak ditemukan di penyimpanan.');
        }

        $this->archiveService->logAccess(
            archive: $archive,
            action: 'downloaded',
            actor: $user,
            version: $archiveVersion,
            request: $request,
            metadata: ['version' => $archiveVersion->version_number],
        );

        return response()->download(
            Storage::disk($archiveVersion->storage_disk)->path($archiveVersion->file_path),
            $archiveVersion->original_name
        );
    }

    /**
     * Hapus (soft-delete) arsip digital.
     */
    public function destroy(Request $request, Archive $archive): RedirectResponse
    {
        $user = $request->user();
        if (! $user->can('delete_archives')) {
            abort(403, 'Anda tidak memiliki izin untuk menghapus arsip.');
        }

        $this->archiveService->logAccess(
            archive: $archive,
            action: 'deleted',
            actor: $user,
            request: $request,
        );

        $archive->delete();

        return redirect()->route('digital-archives.index')->with('success', 'Arsip dipindahkan ke tempat sampah.');
    }

    /**
     * Show private files directly from storage (Legacy / PPDB / Mutasi / GTK Document endpoint).
     * Menerapkan sanitasi nama file, validasi whitelist folder, dan otorisasi kepemilikan objek.
     */
    public function show(Request $request, $type, $filename)
    {
        $allowedTypes = ['student_document', 'staff_document', 'staff_change_attachment', 'mutation', 'ppdb', 'photo'];
        if (! in_array($type, $allowedTypes, true)) {
            abort(404, 'Tipe dokumen tidak valid.');
        }

        $decodedFilename = rawurldecode((string) $filename);
        $cleanFilename = basename($decodedFilename);
        if ($cleanFilename !== $decodedFilename || str_contains($decodedFilename, '..')) {
            abort(400, 'Nama berkas tidak valid.');
        }

        $user = $request->user();
        if (! $user) {
            abort(403, 'Akses tidak diizinkan.');
        }

        $isOwner = false;

        if ($type === 'staff_document') {
            $document = StaffDocument::find(pathinfo($cleanFilename, PATHINFO_FILENAME));
            $isOwner = $document !== null && $user->staff_id !== null && $document->staff_id === $user->staff_id;
        } elseif ($type === 'staff_change_attachment') {
            $attachment = StaffChangeAttachment::with('changeRequest')->find(pathinfo($cleanFilename, PATHINFO_FILENAME));
            $isOwner = $attachment?->changeRequest?->requester_user_id === $user->id;
        }

        $permission = self::DOCUMENT_VIEW_PERMISSIONS[$type] ?? null;
        $canViewByPermission = $permission !== null && $user->can($permission);
        $isChangeReviewer = $type === 'staff_change_attachment'
            && ($user->can('verify_staff_changes') || $user->can('approve_staff_changes'));

        // Otorisasi object-level: pemilik dokumen atau pemegang permission jenis dokumen terkait.
        if (! $isOwner && ! $canViewByPermission && ! $isChangeReviewer) {
            abort(403, 'Anda tidak memiliki hak akses untuk membuka dokumen ini.');
        }

        $path = "archives/{$type}/{$cleanFilename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'File not found or has been uploaded to Google Drive.');
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Retry uploading a document to Google Drive.
     */
    public function retry(Request $request, $type, $id)
    {
        $user = $request->user();
        $permission = self::DOCUMENT_VIEW_PERMISSIONS[$type] ?? null;

        abort_unless(
            $user !== null && $permission !== null && $user->can($permission),
            403,
            'Anda tidak memiliki hak akses untuk memproses ulang dokumen ini.'
        );

        if ($type === 'ppdb') {
            $model = PpdbDocument::findOrFail($id);
            $model->update(['status' => 'pending', 'upload_retries' => 0]);
            UploadToGoogleDriveJob::dispatch('ppdb', $model->id);
        } elseif ($type === 'mutation') {
            $model = StudentMutation::findOrFail($id);
            $model->update(['document_status' => 'pending', 'upload_retries' => 0]);
            UploadToGoogleDriveJob::dispatch('mutation', $model->id);
        } elseif ($type === 'student_document') {
            $model = StudentDocument::findOrFail($id);
            $model->update(['status' => 'pending', 'upload_retries' => 0]);
            UploadToGoogleDriveJob::dispatch('student_document', $model->id);
        } elseif ($type === 'staff_document') {
            $model = StaffDocument::findOrFail($id);
            $model->update(['status' => 'pending', 'upload_retries' => 0]);
            UploadToGoogleDriveJob::dispatch('staff_document', $model->id);
        } elseif ($type === 'photo') {
            $model = Student::findOrFail($id);
            $model->update(['photo_upload_status' => 'pending', 'photo_upload_retries' => 0]);
            UploadToGoogleDriveJob::dispatch('photo', $model->id);
        } else {
            abort(400, 'Invalid document type');
        }

        return redirect()->back()->with('success', 'Upload ulang sedang diproses di latar belakang.');
    }
}
