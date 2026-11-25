<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Archive;
use App\Models\PkkmAssessment;
use App\Models\PkkmComponent;
use App\Models\PkkmEvidence;
use App\Models\PkkmInstrument;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PkkmController extends Controller
{
    public function __construct(
        private ArchiveService $archiveService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewAll = $user && (
            $user->can('view_pkkm')
            || $user->can('manage_pkkm')
            || $user->can('assess_pkkm')
            || $user->can('review_pkkm')
            || $user->can('finalize_pkkm')
        );

        $query = PkkmInstrument::query()
            ->with([
                'academicYear:id,year,semester',
                'headmaster:id,name',
                'finalizer:id,name',
            ])
            ->withCount(['components', 'assessments'])
            ->orderByDesc('period_year')
            ->orderByDesc('created_at');

        if (! $canViewAll && $user) {
            $hasInvolvement = PkkmInstrument::query()
                ->where('headmaster_user_id', $user->id)
                ->orWhereHas('components', fn ($q) => $q->where('pic_user_id', $user->id))
                ->exists();

            abort_unless($hasInvolvement, 403, 'Anda tidak memiliki hak akses untuk melihat daftar instrumen PKKM.');

            $query->where(function ($q) use ($user): void {
                $q->where('headmaster_user_id', $user->id)
                    ->orWhereHas('components', fn ($c) => $c->where('pic_user_id', $user->id));
            });
        }

        $instruments = $query->get();

        return Inertia::render('Pkkm/Index', [
            'instruments' => $instruments,
            'academicYears' => AcademicYear::orderByDesc('year')->get(['id', 'year', 'semester', 'is_active']),
            'headmasters' => User::whereIn('role', ['kepala_madrasah', 'admin'])->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    /**
     * Buat instrumen PKKM baru beserta opsi seed komponen standar 5 unsur yang sepenuhnya dapat diedit/ditambah.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $canManage = $user && $user->can('manage_pkkm');
        if (! $canManage) {
            abort(403, 'Hanya Admin, Kepala Madrasah, atau Kepala TU yang dapat membuat instrumen PKKM.');
        }

        $validated = $request->validate([
            'code' => 'nullable|string|max:60|unique:pkkm_instruments,code',
            'title' => 'required|string|max:255',
            'period_year' => 'required|string|max:20',
            'assessment_type' => 'required|in:annual,four_year',
            'academic_year_id' => 'nullable|uuid|exists:academic_years,id',
            'headmaster_user_id' => 'nullable|uuid|exists:users,id',
            'description' => 'nullable|string|max:2000',
            'seed_default_components' => 'sometimes|boolean',
        ]);

        $instrument = DB::transaction(function () use ($validated, $user): PkkmInstrument {
            $code = ! empty($validated['code'])
                ? strtoupper($validated['code'])
                : sprintf('PKKM/%s/%03d', $validated['period_year'], PkkmInstrument::count() + 1);

            $activeYearId = $validated['academic_year_id']
                ?? AcademicYear::where('is_active', true)->value('id');

            $headmasterId = $validated['headmaster_user_id']
                ?? User::where('role', 'kepala_madrasah')->value('id')
                ?? $user->id;

            $instrument = PkkmInstrument::create([
                'code' => $code,
                'title' => $validated['title'],
                'period_year' => $validated['period_year'],
                'assessment_type' => $validated['assessment_type'],
                'academic_year_id' => $activeYearId,
                'headmaster_user_id' => $headmasterId,
                'description' => $validated['description'] ?? null,
                'status' => PkkmInstrument::STATUS_DRAFT,
                'overall_score' => 0,
                'created_by' => $user->id,
            ]);

            if ($validated['seed_default_components'] ?? true) {
                $this->seedConfigurableBaselineComponents($instrument, $user);
            }

            return $instrument;
        });

        return redirect()
            ->route('pkkm.show', $instrument->id)
            ->with('success', "Instrumen PKKM {$instrument->code} berhasil dibuat dengan komponen dinamis.");
    }

    public function show(Request $request, PkkmInstrument $instrument): Response
    {
        $this->authorizeInstrumentAccess($request, $instrument);
        $user = $request->user();

        $instrument->load([
            'academicYear:id,year,semester',
            'headmaster:id,name',
            'finalizer:id,name',
            'components.pic:id,name,role',
            'components.assessment.assessor:id,name',
            'components.evidence.archive:id,archive_number,title,visibility,allowed_roles,created_by,owner_user_id,owner_staff_id',
            'components.evidence.uploader:id,name',
        ]);

        return Inertia::render('Pkkm/Show', [
            'instrument' => $this->filterInstrumentArchives($instrument, $user),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'archives' => $this->archiveService->recentViewable($user, 50),
        ]);
    }

    /**
     * Tambah komponen penilaian PKKM secara dinamis (tidak dikunci di kode).
     */
    public function storeComponent(Request $request, PkkmInstrument $instrument): RedirectResponse
    {
        $user = $request->user();
        $canManage = $user && $user->can('manage_pkkm');
        if (! $canManage) {
            abort(403, 'Anda tidak memiliki izin untuk menambah komponen PKKM.');
        }

        if ($instrument->status === PkkmInstrument::STATUS_FINALIZED) {
            return redirect()->back()->withErrors(['instrument' => 'Instrumen PKKM sudah difinalisasi.']);
        }

        $validated = $request->validate([
            'category_code' => 'required|string|max:40',
            'category_name' => 'required|string|max:255',
            'code' => 'required|string|max:40',
            'title' => 'required|string|max:255',
            'indicator_description' => 'nullable|string|max:2000',
            'required_evidence_hint' => 'nullable|string|max:1000',
            'weight' => 'required|numeric|min:0.1|max:100',
            'max_score' => 'required|integer|min:1|max:100',
            'pic_user_id' => 'nullable|uuid|exists:users,id',
        ]);

        $sortOrder = ((int) $instrument->components()->max('sort_order')) + 1;

        PkkmComponent::updateOrCreate(
            [
                'pkkm_instrument_id' => $instrument->id,
                'code' => strtoupper($validated['code']),
            ],
            [
                'category_code' => strtoupper($validated['category_code']),
                'category_name' => $validated['category_name'],
                'title' => $validated['title'],
                'indicator_description' => $validated['indicator_description'] ?? null,
                'required_evidence_hint' => $validated['required_evidence_hint'] ?? null,
                'weight' => (float) $validated['weight'],
                'max_score' => (int) $validated['max_score'],
                'sort_order' => $sortOrder,
                'pic_user_id' => $validated['pic_user_id'] ?? $user->id,
            ]
        );

        $instrument->recalculateScore();

        return redirect()->back()->with('success', 'Komponen indikator PKKM berhasil disimpan.');
    }

    /**
     * Simpan skor evaluasi diri / skor verifikasi asesor pada komponen PKKM.
     */
    public function saveAssessment(Request $request, PkkmComponent $component): RedirectResponse
    {
        $user = $request->user();
        $instrument = $component->instrument;

        $isEvaluatorRole = $user && $user->can('assess_pkkm');
        $isHeadmaster = $instrument->headmaster_user_id === $user?->id;
        $isComponentPic = $component->pic_user_id === $user?->id;

        abort_unless(
            $isEvaluatorRole || $isHeadmaster || $isComponentPic,
            403,
            'Hanya asesor atau penanggung jawab komponen yang dapat mengisi asesmen.'
        );

        if ($instrument->status === PkkmInstrument::STATUS_FINALIZED) {
            return redirect()->back()->withErrors(['instrument' => 'Instrumen PKKM sudah difinalisasi dan terkunci.']);
        }

        $validated = $request->validate([
            'self_score' => 'nullable|numeric|min:0|max:'.$component->max_score,
            'verified_score' => 'nullable|numeric|min:0|max:'.$component->max_score,
            'self_notes' => 'nullable|string|max:1000',
            'assessor_notes' => 'nullable|string|max:1000',
        ]);

        $isPic = $component->pic_user_id === $user->id;
        $hasVerifiedScore = isset($validated['verified_score']) && $validated['verified_score'] !== null;

        if ($hasVerifiedScore) {
            abort_if($isPic, 403, 'PIC komponen tidak diperbolehkan memverifikasi skor pada komponennya sendiri.');
            abort_unless($isEvaluatorRole, 403, 'Hanya Asesor/Manajemen independen yang dapat mengisi nilai verifikasi.');
        }

        $existingAssessment = $component->assessment;
        $selfScore = $validated['self_score'] ?? $existingAssessment?->self_score ?? 0;

        PkkmAssessment::updateOrCreate(
            [
                'pkkm_instrument_id' => $instrument->id,
                'pkkm_component_id' => $component->id,
            ],
            [
                'self_score' => (float) $selfScore,
                'verified_score' => $hasVerifiedScore
                    ? (float) $validated['verified_score']
                    : ($existingAssessment?->verified_score),
                'self_notes' => $validated['self_notes'] ?? $existingAssessment?->self_notes,
                'assessor_notes' => $hasVerifiedScore ? ($validated['assessor_notes'] ?? null) : $existingAssessment?->assessor_notes,
                'status' => $hasVerifiedScore ? 'verified' : ($existingAssessment?->status ?? 'submitted'),
                'assessed_by' => $user->id,
                'assessed_at' => now(),
            ]
        );

        if ($instrument->status === PkkmInstrument::STATUS_DRAFT) {
            $instrument->update(['status' => PkkmInstrument::STATUS_IN_PROGRESS]);
        }

        $instrument->recalculateScore();

        return redirect()->back()->with('success', "Penilaian komponen {$component->code} diperbarui.");
    }

    /**
     * Unggah atau kaitkan dokumen bukti fisik (Arsip Digital / File / URL) pada komponen PKKM.
     */
    public function storeEvidence(Request $request, PkkmComponent $component): RedirectResponse
    {
        $user = $request->user();
        $instrument = $component->instrument;
        $isManager = $user && $user->can('manage_pkkm');
        abort_unless(
            $isManager || $component->pic_user_id === $user->id,
            403,
            'Hanya pengelola PKKM atau penanggung jawab komponen yang dapat mengunggah bukti.'
        );
        if ($instrument->status === PkkmInstrument::STATUS_FINALIZED) {
            return redirect()->back()->withErrors(['instrument' => 'Instrumen PKKM sudah difinalisasi dan terkunci.']);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'archive_id' => 'nullable|uuid|exists:archives,id',
            'file' => 'nullable|file|max:15360|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip',
            'external_url' => 'nullable|url|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $filePath = null;
        $originalName = null;
        $mimeType = null;
        $fileSize = 0;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
            $filePath = $file->storeAs("pkkm-evidence/{$component->pkkm_instrument_id}", Str::random(16).'.'.$ext, 'local');
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType();
            $fileSize = (int) $file->getSize();
        }

        if (isset($validated['archive_id'])) {
            $archive = Archive::findOrFail($validated['archive_id']);
            abort_unless(
                $this->archiveService->canView($user, $archive),
                403,
                'Anda tidak berhak menautkan arsip yang tidak dapat Anda akses.'
            );
        }

        PkkmEvidence::create([
            'pkkm_component_id' => $component->id,
            'archive_id' => $validated['archive_id'] ?? null,
            'title' => $validated['title'],
            'file_path' => $filePath,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'external_url' => $validated['external_url'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'verification_status' => 'pending',
            'uploaded_by' => $user->id,
        ]);

        return redirect()->back()->with('success', "Bukti komponen {$component->code} berhasil ditambahkan.");
    }

    public function reviewEvidence(Request $request, PkkmEvidence $evidence): RedirectResponse
    {
        $user = $request->user();
        $instrument = $evidence->component->instrument;
        $canReviewRole = $user && $user->can('review_pkkm');
        $isUploader = $user->id === $evidence->uploaded_by;
        $isComponentPic = $user->id === $evidence->component->pic_user_id;

        abort_unless(
            $canReviewRole
                && ! $isUploader
                && ! $isComponentPic
                && $instrument->status !== PkkmInstrument::STATUS_FINALIZED,
            403,
            'Bukti hanya dapat diverifikasi pihak berwenang selain pengunggah dan penanggung jawab komponen sebelum finalisasi.'
        );

        $validated = $request->validate([
            'verification_status' => 'required|in:verified,rejected',
        ]);

        $evidence->update(['verification_status' => $validated['verification_status']]);

        return redirect()->back()->with('success', 'Status verifikasi bukti diperbarui.');
    }

    /**
     * Finalisasi instrumen PKKM oleh Kepala Madrasah / Admin.
     * Menolak finalisasi apabila instrumen belum memiliki komponen, komponen belum memiliki
     * asesmen dengan skor terverifikasi (verified_score), atau belum memiliki bukti terverifikasi.
     */
    public function finalize(Request $request, PkkmInstrument $instrument): RedirectResponse
    {
        $user = $request->user();
        $canFinalize = $user && $user->can('finalize_pkkm');
        if (! $canFinalize) {
            abort(403, 'Hanya Kepala Madrasah atau Admin yang dapat memfinalisasi instrumen PKKM.');
        }

        if ($instrument->status === PkkmInstrument::STATUS_FINALIZED) {
            return redirect()->back()->withErrors(['instrument' => 'Instrumen PKKM sudah difinalisasi dan terkunci.']);
        }

        $components = $instrument->components()->with(['assessment', 'evidence'])->get();
        if ($components->isEmpty()) {
            return redirect()->back()->withErrors([
                'instrument' => 'Instrumen PKKM tidak dapat difinalisasi karena belum memiliki komponen penilaian.',
            ]);
        }

        $missingAssessmentCodes = [];
        $missingVerifiedScoreCodes = [];
        $missingVerifiedEvidenceCodes = [];
        $pendingEvidenceCodes = [];

        foreach ($components as $component) {
            $assessment = $component->assessment;
            if ($assessment === null) {
                $missingAssessmentCodes[] = $component->code;
            } elseif ($assessment->verified_score === null) {
                $missingVerifiedScoreCodes[] = $component->code;
            }

            if ($component->evidence->where('verification_status', 'pending')->isNotEmpty()) {
                $pendingEvidenceCodes[] = $component->code;
            }

            if ($component->evidence->where('verification_status', 'verified')->isEmpty()) {
                $missingVerifiedEvidenceCodes[] = $component->code;
            }
        }

        $completenessErrors = [];
        if ($missingAssessmentCodes !== []) {
            $completenessErrors[] = 'Komponen belum dinilai: '.implode(', ', $missingAssessmentCodes).'.';
        }
        if ($missingVerifiedScoreCodes !== []) {
            $completenessErrors[] = 'Komponen belum memiliki skor verifikasi asesor (verified_score): '.implode(', ', $missingVerifiedScoreCodes).'.';
        }
        if ($pendingEvidenceCodes !== []) {
            $completenessErrors[] = 'Komponen masih memiliki bukti berstatus pending: '.implode(', ', $pendingEvidenceCodes).'.';
        }
        if ($missingVerifiedEvidenceCodes !== []) {
            $completenessErrors[] = 'Komponen belum memiliki bukti fisik terverifikasi: '.implode(', ', $missingVerifiedEvidenceCodes).'.';
        }

        if ($completenessErrors !== []) {
            return redirect()->back()->withErrors([
                'instrument' => 'Instrumen PKKM belum memenuhi syarat kelengkapan finalisasi: '.implode(' ', $completenessErrors),
            ]);
        }

        $instrument->recalculateScore();
        $instrument->update([
            'status' => PkkmInstrument::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by' => $user->id,
        ]);

        return redirect()->back()->with('success', "Instrumen PKKM {$instrument->code} resmi difinalisasi.");
    }

    private function authorizeInstrumentAccess(Request $request, PkkmInstrument $instrument): void
    {
        $user = $request->user();
        $isManager = $user && (
            $user->can('view_pkkm')
            || $user->can('manage_pkkm')
            || $user->can('assess_pkkm')
            || $user->can('review_pkkm')
            || $user->can('finalize_pkkm')
        );
        $isHeadmaster = $instrument->headmaster_user_id === $user?->id;
        $isPic = $instrument->components()->where('pic_user_id', $user?->id)->exists();

        abort_unless($isManager || $isHeadmaster || $isPic, 403, 'Anda tidak memiliki hak akses untuk melihat instrumen PKKM ini.');
    }

    private function filterInstrumentArchives(PkkmInstrument $instrument, User $user): PkkmInstrument
    {
        $instrument->components->each(function (PkkmComponent $component) use ($user): void {
            $component->evidence->each(function (PkkmEvidence $evidence) use ($user): void {
                $this->archiveService->hideUnviewableRelation($user, $evidence);
            });
        });

        return $instrument;
    }

    private function seedConfigurableBaselineComponents(PkkmInstrument $instrument, User $actor): void
    {
        $defaults = [
            [
                'category_code' => 'A-USAHA',
                'category_name' => 'A. Usaha Pengembangan Madrasah',
                'code' => '1.1',
                'title' => 'Mengembangkan madrasah sesuai dengan kebutuhan',
                'indicator_description' => 'Dokumen struktur organisasi, RKJM/RKT, dan bagan tata kelola madrasah.',
                'required_evidence_hint' => 'SK Struktur Organisasi, Dokumen RKJM/RKT, Notulen Rapat',
                'weight' => 1.0,
            ],
            [
                'category_code' => 'B-MANAJERIAL',
                'category_name' => 'B. Pelaksanaan Tugas Manajerial',
                'code' => '2.1',
                'title' => 'Menyusun perencanaan dan mengelola sumber daya madrasah',
                'indicator_description' => 'Perencanaan program kerja, pengelolaan pendidik/tenaga kependidikan, dan administrasi.',
                'required_evidence_hint' => 'Program Kerja Tahunan, SK Pembagian Tugas GTK, Laporan Realisasi',
                'weight' => 1.0,
            ],
            [
                'category_code' => 'C-KEWIRAUSAHAAN',
                'category_name' => 'C. Pengembangan Kewirausahaan',
                'code' => '3.1',
                'title' => 'Menciptakan inovasi yang bermanfaat bagi pengembangan madrasah',
                'indicator_description' => 'Program inovasi digitalisasi layanan administrasi dan kemitraan.',
                'required_evidence_hint' => 'Dokumentasi Inovasi Paperless, MoU Kemitraan',
                'weight' => 1.0,
            ],
            [
                'category_code' => 'D-SUPERVISI',
                'category_name' => 'D. Supervisi kepada Guru dan Tenaga Kependidikan',
                'code' => '4.1',
                'title' => 'Merencanakan, melaksanakan, dan menindaklanjuti supervisi akademik',
                'indicator_description' => 'Program supervisi guru, instrumen observasi kelas, dan tindak lanjut hasil supervisi.',
                'required_evidence_hint' => 'Jadwal Supervisi, Instrumen & Laporan Hasil Supervisi',
                'weight' => 1.0,
            ],
            [
                'category_code' => 'E-HASIL',
                'category_name' => 'E. Hasil Kinerja Kepala Madrasah',
                'code' => '5.1',
                'title' => 'Prestasi akademik dan non-akademik siswa, guru, serta madrasah',
                'indicator_description' => 'Rekapitulasi piagam penghargaan, akreditasi, dan capaian mutu.',
                'required_evidence_hint' => 'Sertifikat Akreditasi, Piagam Prestasi Siswa & GTK',
                'weight' => 1.0,
            ],
        ];

        foreach ($defaults as $idx => $item) {
            PkkmComponent::create([
                'pkkm_instrument_id' => $instrument->id,
                'category_code' => $item['category_code'],
                'category_name' => $item['category_name'],
                'code' => $item['code'],
                'title' => $item['title'],
                'indicator_description' => $item['indicator_description'],
                'required_evidence_hint' => $item['required_evidence_hint'],
                'weight' => $item['weight'],
                'max_score' => 100,
                'sort_order' => $idx + 1,
                'pic_user_id' => $actor->id,
            ]);
        }
    }
}
