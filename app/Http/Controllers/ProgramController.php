<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Archive;
use App\Models\OrganizationUnit;
use App\Models\Program;
use App\Models\ProgramActivity;
use App\Models\ProgramEvidence;
use App\Models\ProgramReport;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProgramController extends Controller
{
    public function __construct(
        private ArchiveService $archiveService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $query = Program::query()
            ->with([
                'academicYear:id,year,semester',
                'organizationUnit:id,code,name',
                'pic:id,name',
                'approver:id,name',
            ])
            ->withCount(['activities', 'reports'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('unit_id')) {
            $query->where('organization_unit_id', (string) $request->query('unit_id'));
        }

        if (! $user->can('view_programs')) {
            $query->where(function ($programQuery) use ($user): void {
                $programQuery->where('pic_user_id', $user->id)
                    ->orWhereHas('activities', fn ($activityQuery) => $activityQuery->where('pic_user_id', $user->id));
            });
        }

        $programs = $query->limit(100)->get();

        return Inertia::render('Programs/Index', [
            'programs' => $programs,
            'units' => OrganizationUnit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'academicYears' => AcademicYear::orderByDesc('year')->get(['id', 'year', 'semester', 'is_active']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'filters' => [
                'status' => (string) $request->query('status', ''),
                'unit_id' => (string) $request->query('unit_id', ''),
            ],
            'stats' => [
                'total' => $programs->count(),
                'approved' => $programs->whereIn('status', [Program::STATUS_APPROVED, Program::STATUS_IN_PROGRESS, Program::STATUS_COMPLETED])->count(),
                'total_budget' => (int) $programs->sum('budget_amount'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->can('create_programs')) {
            abort(403, 'Anda tidak memiliki izin untuk membuat program kerja.');
        }

        $validated = $request->validate([
            'code' => 'nullable|string|max:60|unique:programs,code',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'academic_year_id' => 'nullable|uuid|exists:academic_years,id',
            'organization_unit_id' => 'nullable|uuid|exists:org_units,id',
            'pic_user_id' => 'nullable|uuid|exists:users,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget_amount' => 'nullable|integer|min:0',
        ]);

        $code = ! empty($validated['code'])
            ? strtoupper($validated['code'])
            : sprintf('PRG/%s/%04d', now()->format('Y'), Program::withTrashed()->count() + 1);

        $activeYearId = $validated['academic_year_id']
            ?? AcademicYear::where('is_active', true)->value('id');

        $program = Program::create([
            'code' => $code,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'academic_year_id' => $activeYearId,
            'organization_unit_id' => $validated['organization_unit_id'] ?? null,
            'pic_user_id' => $validated['pic_user_id'] ?? $user->id,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'budget_amount' => (int) ($validated['budget_amount'] ?? 0),
            'status' => Program::STATUS_DRAFT,
            'progress_percent' => 0,
            'created_by' => $user->id,
        ]);

        return redirect()
            ->route('programs.show', $program->id)
            ->with('success', "Program Kerja {$program->code} berhasil dibuat.");
    }

    private function authorizeProgramManager(User $user, Program $program): void
    {
        $isManager = $user->can('manage_programs');
        abort_unless($isManager || $program->pic_user_id === $user->id, 403, 'Akses program tidak diizinkan.');
    }

    private function authorizeProgramAccess(Request $request, Program $program): void
    {
        $user = $request->user();
        $isManager = $user->can('manage_programs');
        $isActivityPic = $program->activities()->where('pic_user_id', $user->id)->exists();
        abort_unless($isManager || $program->pic_user_id === $user->id || $isActivityPic, 403, 'Akses program tidak diizinkan.');
    }

    private function filterProgramArchives(Program $program, User $user): Program
    {
        $program->reports->each(function (ProgramReport $report) use ($user): void {
            $this->archiveService->hideUnviewableRelation($user, $report->evidence);
        });

        return $program;
    }

    public function show(Request $request, Program $program): Response
    {
        $this->authorizeProgramAccess($request, $program);
        $user = $request->user();

        $program->load([
            'academicYear:id,year,semester',
            'organizationUnit:id,code,name',
            'pic:id,name,role',
            'approver:id,name',
            'creator:id,name',
            'activities.pic:id,name',
            'reports.activity:id,title',
            'reports.submitter:id,name',
            'reports.verifier:id,name',
            'reports.approver:id,name',
            'reports.evidence.archive:id,archive_number,title',
        ]);

        return Inertia::render('Programs/Show', [
            'program' => $this->filterProgramArchives($program, $user),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'archives' => $this->archiveService->recentViewable($user, 50),
        ]);
    }

    /**
     * Transisi status program kerja (submit -> verify -> approve / reject).
     */
    public function updateStatus(Request $request, Program $program): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'status' => 'required|in:draft,submitted,verified,approved,in_progress,completed,rejected',
            'approval_notes' => 'nullable|string|max:1000',
        ]);

        $this->authorizeProgramManager($user, $program);
        $targetStatus = $validated['status'];
        if ($targetStatus === Program::STATUS_SUBMITTED) {
            abort_unless($user->id === $program->pic_user_id, 403, 'Hanya PIC program yang dapat mengajukan program.');
        }

        $allowedTransitions = [
            Program::STATUS_DRAFT => [Program::STATUS_SUBMITTED],
            Program::STATUS_SUBMITTED => [Program::STATUS_VERIFIED, Program::STATUS_REJECTED],
            Program::STATUS_VERIFIED => [Program::STATUS_APPROVED, Program::STATUS_REJECTED],
            Program::STATUS_APPROVED => [Program::STATUS_IN_PROGRESS],
            Program::STATUS_IN_PROGRESS => [Program::STATUS_COMPLETED],
        ];

        if (! in_array($targetStatus, $allowedTransitions[$program->status] ?? [], true)) {
            abort(422, 'Transisi status program tidak valid.');
        }

        if (in_array($targetStatus, [Program::STATUS_APPROVED, Program::STATUS_REJECTED], true)) {
            if (! $user->can('approve_programs')) {
                abort(403, 'Hanya Kepala Madrasah atau Admin yang dapat menyetujui/menolak program kerja.');
            }
        }

        if ($targetStatus === Program::STATUS_VERIFIED) {
            if (! $user->can('verify_programs')) {
                abort(403, 'Hanya Kepala TU, Kepala Madrasah, atau Admin yang dapat memverifikasi program kerja.');
            }
        }

        if ($targetStatus === Program::STATUS_IN_PROGRESS) {
            if (! $user->can('manage_programs') && $user->id !== $program->pic_user_id) {
                abort(403, 'Hanya PIC program atau pengelola yang dapat memulai pelaksanaan program kerja.');
            }
        }

        if ($targetStatus === Program::STATUS_COMPLETED) {
            if (! $user->can('manage_programs') && $user->id !== $program->pic_user_id) {
                abort(403, 'Anda tidak berwenang untuk menyelesaikan program kerja ini.');
            }

            // Syarat penyelesaian program kerja:
            // 1. Memiliki minimal 1 kegiatan terdaftar
            abort_if($program->activities()->count() === 0, 422, 'Program kerja tidak dapat diselesaikan tanpa memiliki kegiatan terdaftar.');

            // 2. Seluruh kegiatan harus berstatus completed
            abort_if(
                $program->activities()->where('status', '!=', 'completed')->exists(),
                422,
                'Seluruh kegiatan program kerja harus berstatus selesai (completed) sebelum program dapat ditutup.'
            );

            // 3. Capaian progres rata-rata harus 100%
            abort_if(
                (int) $program->progress_percent < 100,
                422,
                'Progres pelaksanaan program belum mencapai 100%.'
            );

            // 4. Seluruh laporan kegiatan harus berstatus disetujui (STATUS_APPROVED)
            abort_if(
                $program->reports()->where('status', '!=', ProgramReport::STATUS_APPROVED)->exists(),
                422,
                'Masih terdapat laporan kegiatan yang belum disetujui oleh Kepala Madrasah.'
            );

            // 5. Minimal terdapat 1 laporan kegiatan yang telah disetujui
            abort_if(
                $program->reports()->where('status', ProgramReport::STATUS_APPROVED)->count() === 0,
                422,
                'Program kerja memerlukan minimal satu laporan kegiatan yang telah disetujui untuk dapat diselesaikan.'
            );
        }

        $updates = [
            'status' => $targetStatus,
            'approval_notes' => $validated['approval_notes'] ?? $program->approval_notes,
        ];

        if ($targetStatus === Program::STATUS_APPROVED) {
            $updates['approved_by'] = $user->id;
            $updates['approved_at'] = now();
        }

        $program->update($updates);

        return redirect()->back()->with('success', "Status program diperbarui menjadi {$targetStatus}.");
    }

    /**
     * Tambah kegiatan pada program kerja.
     */
    public function storeActivity(Request $request, Program $program): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeProgramManager($user, $program);
        abort_if(in_array($program->status, [Program::STATUS_COMPLETED, Program::STATUS_REJECTED], true), 422, 'Program telah ditutup.');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_indicator' => 'nullable|string|max:255',
            'pic_user_id' => 'nullable|uuid|exists:users,id',
            'scheduled_start' => 'nullable|date',
            'scheduled_end' => 'nullable|date|after_or_equal:scheduled_start',
            'budget_allocation' => 'nullable|integer|min:0',
        ]);

        ProgramActivity::create([
            'program_id' => $program->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_indicator' => $validated['target_indicator'] ?? null,
            'pic_user_id' => $validated['pic_user_id'] ?? $request->user()->id,
            'scheduled_start' => $validated['scheduled_start'] ?? null,
            'scheduled_end' => $validated['scheduled_end'] ?? null,
            'budget_allocation' => (int) ($validated['budget_allocation'] ?? 0),
            'progress_percent' => 0,
            'status' => 'planned',
        ]);

        $program->recalculateProgress();

        return redirect()->back()->with('success', 'Kegiatan program berhasil ditambahkan.');
    }

    /**
     * Simpan laporan realisasi pelaksanaan kegiatan beserta bukti pendukung (file atau tautan arsip).
     */
    public function storeReport(Request $request, Program $program): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeProgramManager($user, $program);
        abort_unless(in_array($program->status, [Program::STATUS_APPROVED, Program::STATUS_IN_PROGRESS], true), 422, 'Laporan hanya dapat dikirim setelah program disetujui.');

        $validated = $request->validate([
            'program_activity_id' => 'nullable|uuid|exists:program_activities,id,program_id,'.$program->id,
            'title' => 'required|string|max:255',
            'report_date' => 'required|date',
            'summary' => 'required|string|max:4000',
            'outcomes' => 'nullable|string|max:2000',
            'obstacles' => 'nullable|string|max:2000',
            'realized_budget' => 'nullable|integer|min:0',
            'achieved_percent' => 'required|integer|min:0|max:100',
            'evidence_title' => 'nullable|string|max:255',
            'evidence_archive_id' => 'nullable|uuid|exists:archives,id',
            'evidence_file' => 'nullable|file|max:10240|mimes:pdf,doc,docx,jpg,jpeg,png,zip',
            'evidence_url' => 'nullable|url|max:500',
        ]);

        if (! empty($validated['evidence_archive_id'])) {
            $archive = Archive::findOrFail($validated['evidence_archive_id']);
            abort_unless(
                $this->archiveService->canView($user, $archive),
                403,
                'Anda tidak berhak menautkan arsip yang tidak dapat Anda akses.'
            );
        }

        DB::transaction(function () use ($request, $program, $user, $validated): void {
            $report = ProgramReport::create([
                'program_id' => $program->id,
                'program_activity_id' => $validated['program_activity_id'] ?? null,
                'title' => $validated['title'],
                'report_date' => $validated['report_date'],
                'summary' => $validated['summary'],
                'outcomes' => $validated['outcomes'] ?? null,
                'obstacles' => $validated['obstacles'] ?? null,
                'realized_budget' => (int) ($validated['realized_budget'] ?? 0),
                'achieved_percent' => (int) $validated['achieved_percent'],
                'status' => ProgramReport::STATUS_SUBMITTED,
                'submitted_by' => $user->id,
            ]);

            if (! empty($validated['program_activity_id'])) {
                $activity = ProgramActivity::where('program_id', $program->id)
                    ->whereKey($validated['program_activity_id'])
                    ->firstOrFail();

                $activity->update([
                    'progress_percent' => (int) $validated['achieved_percent'],
                    'budget_realization' => $activity->budget_realization + (int) ($validated['realized_budget'] ?? 0),
                    'status' => ((int) $validated['achieved_percent']) >= 100 ? 'completed' : 'in_progress',
                ]);
            }

            if (
                $request->hasFile('evidence_file')
                || ! empty($validated['evidence_archive_id'])
                || ! empty($validated['evidence_url'])
            ) {
                $filePath = null;
                $originalName = null;
                $mimeType = null;
                $fileSize = 0;

                if ($request->hasFile('evidence_file')) {
                    $file = $request->file('evidence_file');
                    $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
                    $filePath = $file->storeAs("program-evidence/{$program->id}", Str::random(16).'.'.$ext, 'local');
                    $originalName = $file->getClientOriginalName();
                    $mimeType = $file->getClientMimeType();
                    $fileSize = (int) $file->getSize();
                }

                ProgramEvidence::create([
                    'program_report_id' => $report->id,
                    'archive_id' => $validated['evidence_archive_id'] ?? null,
                    'title' => $validated['evidence_title'] ?? ('Bukti: '.$report->title),
                    'file_path' => $filePath,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'external_url' => $validated['evidence_url'] ?? null,
                    'uploaded_by' => $user->id,
                ]);
            }

            $program->recalculateProgress();
        });

        return redirect()->back()->with('success', 'Laporan realisasi kegiatan berhasil dikirim.');
    }

    /**
     * Verifikasi TU atau Persetujuan Kepala Madrasah atas laporan kegiatan.
     */
    public function reviewReport(Request $request, ProgramReport $report): RedirectResponse
    {
        $user = $request->user();
        $program = $report->program;
        $this->authorizeProgramManager($user, $program);
        abort_unless($report->submitted_by !== $user->id, 403, 'Pengirim laporan tidak dapat meninjau laporannya sendiri.');

        $validated = $request->validate([
            'action' => 'required|in:verify,approve,revision',
            'review_notes' => 'nullable|string|max:1000',
        ]);

        if ($validated['action'] === 'verify') {
            abort_unless($report->status === ProgramReport::STATUS_SUBMITTED, 422, 'Hanya laporan terkirim yang dapat diverifikasi.');
            if (! $user->can('verify_programs')) {
                abort(403, 'Hanya Kepala TU, Kepala Madrasah, atau Admin yang dapat memverifikasi laporan.');
            }

            $report->update([
                'status' => ProgramReport::STATUS_VERIFIED,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);
        } elseif ($validated['action'] === 'approve') {
            abort_unless($report->status === ProgramReport::STATUS_VERIFIED, 422, 'Hanya laporan terverifikasi yang dapat disetujui.');
            if (! $user->can('approve_programs')) {
                abort(403, 'Hanya Kepala Madrasah atau Admin yang dapat menyetujui laporan kegiatan.');
            }

            $report->update([
                'status' => ProgramReport::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);
        } else {
            abort_unless(in_array($report->status, [ProgramReport::STATUS_SUBMITTED, ProgramReport::STATUS_VERIFIED], true), 422, 'Tahap laporan tidak dapat diminta revisi.');
            if (! $user->can('verify_programs')) {
                abort(403);
            }

            $report->update([
                'status' => ProgramReport::STATUS_REVISION,
                'review_notes' => $validated['review_notes'] ?? null,
            ]);
        }

        return redirect()->back()->with('success', 'Status review laporan kegiatan diperbarui.');
    }
}
