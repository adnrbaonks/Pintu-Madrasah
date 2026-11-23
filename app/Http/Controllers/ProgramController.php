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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProgramController extends Controller
{
    public function index(Request $request): Response
    {
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
        if (! in_array($user->role, ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'], true)) {
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

    public function show(Program $program): Response
    {
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
            'program' => $program,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'archives' => Archive::orderByDesc('created_at')->limit(50)->get(['id', 'archive_number', 'title']),
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

        $targetStatus = $validated['status'];

        if (in_array($targetStatus, [Program::STATUS_APPROVED, Program::STATUS_REJECTED], true)) {
            if (! in_array($user->role, ['admin', 'kepala_madrasah'], true)) {
                abort(403, 'Hanya Kepala Madrasah atau Admin yang dapat menyetujui/menolak program kerja.');
            }
        }

        if ($targetStatus === Program::STATUS_VERIFIED) {
            if (! in_array($user->role, ['admin', 'kepala_madrasah', 'kaur_tu'], true)) {
                abort(403, 'Hanya Kepala TU, Kepala Madrasah, atau Admin yang dapat memverifikasi program kerja.');
            }
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

        $validated = $request->validate([
            'program_activity_id' => 'nullable|uuid|exists:program_activities,id',
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
                    ->first();

                if ($activity) {
                    $activity->update([
                        'progress_percent' => (int) $validated['achieved_percent'],
                        'budget_realization' => $activity->budget_realization + (int) ($validated['realized_budget'] ?? 0),
                        'status' => ((int) $validated['achieved_percent']) >= 100 ? 'completed' : 'in_progress',
                    ]);
                }
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
        $validated = $request->validate([
            'action' => 'required|in:verify,approve,revision',
            'review_notes' => 'nullable|string|max:1000',
        ]);

        if ($validated['action'] === 'verify') {
            if (! in_array($user->role, ['admin', 'kepala_madrasah', 'kaur_tu'], true)) {
                abort(403, 'Hanya Kepala TU, Kepala Madrasah, atau Admin yang dapat memverifikasi laporan.');
            }

            $report->update([
                'status' => ProgramReport::STATUS_VERIFIED,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);
        } elseif ($validated['action'] === 'approve') {
            if (! in_array($user->role, ['admin', 'kepala_madrasah'], true)) {
                abort(403, 'Hanya Kepala Madrasah atau Admin yang dapat menyetujui laporan kegiatan.');
            }

            $report->update([
                'status' => ProgramReport::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);
        } else {
            if (! in_array($user->role, ['admin', 'kepala_madrasah', 'kaur_tu'], true)) {
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
