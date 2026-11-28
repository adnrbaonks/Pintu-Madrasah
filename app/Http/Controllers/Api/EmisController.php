<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CommitEmisGtkStagingJob;
use App\Jobs\CommitEmisStagingJob;
use App\Jobs\PullEmisGtkDataJob;
use App\Jobs\PullEmisStudentDataJob;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\EmisGtkStaging;
use App\Models\EmisImportBatch;
use App\Models\EmisImportSession;
use App\Models\EmisImportSessionPage;
use App\Models\EmisStaging;
use App\Models\EmisUpdateTask;
use App\Models\IntegrationLog;
use App\Models\Staff;
use App\Models\Student;
use App\Services\DataOwnershipService;
use App\Services\EmisPiiSanitizer;
use App\Services\EmisReconciliationService;
use App\Services\EmisService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EmisController extends Controller
{
    public function __construct(
        private readonly EmisService $emis,
        private readonly EmisReconciliationService $reconciliation,
        private readonly DataOwnershipService $ownership,
        private readonly EmisPiiSanitizer $pii,
    ) {}

    // ── Terima Token EMIS Siswa dari Bookmarklet ──────────────────────

    /**
     * POST /api/emis/token
     * Menerima JWT token yang dikirim bookmarklet dari browser staf.
     */
    public function receiveToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|min:20',
            'source' => 'sometimes|string',
        ]);

        $token = $request->input('token');

        // Validasi format JWT (3 bagian dipisah titik)
        if (substr_count($token, '.') !== 2) {
            IntegrationLog::record('emis_siswa', 'emis_token.rejected', 'failed', [
                'reason' => 'invalid_jwt_segments',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Format token tidak valid. Pastikan sudah login ke EMIS.',
                'error' => ['code' => 'INVALID_JWT_FORMAT'],
                'request_id' => $request->attributes->get('request_id'),
            ], 422);
        }

        // Decode klaim payload JWT (tanpa klaim verifikasi signature) untuk cek masa berlaku exp
        $segments = explode('.', $token);
        $payloadJson = base64_decode(strtr($segments[1], '-_', '+/'), true);
        $payload = is_string($payloadJson) ? json_decode($payloadJson, true) : null;

        if (is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp'])) {
            if ((int) $payload['exp'] < time()) {
                IntegrationLog::record('emis_siswa', 'emis_token.rejected', 'failed', [
                    'reason' => 'jwt_expired',
                    'exp' => (int) $payload['exp'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Token JWT EMIS sudah kedaluwarsa. Silakan muat ulang halaman EMIS dan kirim kembali.',
                    'error' => ['code' => 'EXPIRED_JWT_TOKEN'],
                    'request_id' => $request->attributes->get('request_id'),
                ], 422);
            }
        }

        $this->emis->storeEmisToken($token);

        Log::info('[API] Token EMIS siswa diterima dari bookmarklet', [
            'ip' => $request->ip(),
        ]);

        IntegrationLog::record('emis_siswa', 'emis_token.received', 'success', [
            'source' => $request->input('source', 'bookmarklet'),
            'has_exp' => is_array($payload) && isset($payload['exp']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Token diterima! Silakan klik tombol "Tarik Data" di aplikasi untuk memulai sinkronisasi.',
            'ttl_seconds' => (int) env('EMIS_TOKEN_TTL', 7200),
        ]);
    }

    // ── Terima Cookie EMIS GTK dari Bookmarklet ──────────────────────

    /**
     * POST /api/emis-gtk/token
     * Menerima cookie Django session dari bookmarklet (bukan JWT).
     *
     * Website emisgtk.kemenag.go.id menggunakan SSR + Django session,
     * bukan REST API JSON + JWT Bearer Token.
     *
     * Payload yang dikirim bookmarklet:
     * {
     *   "csrftoken": "...",
     *   "sessionid": "...",
     *   "source": "bookmarklet"
     * }
     */
    public function receiveGtkToken(Request $request): JsonResponse
    {
        // Support dua mode: cookie-based (baru) dan JWT legacy
        if ($request->has('csrftoken') || $request->has('cookies')) {
            // Mode baru: cookie Django session
            $cookies = $request->has('cookies')
                ? $request->input('cookies')
                : $request->only(['csrftoken', 'sessionid']);

            if (empty($cookies['csrftoken']) && empty($cookies['sessionid'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cookie csrftoken dan sessionid tidak ditemukan.',
                ], 422);
            }

            $this->emis->storeGtkCookies($cookies);

            Log::info('[API] Cookie EMIS GTK diterima dari bookmarklet', [
                'ip' => $request->ip(),
                'has_csrf' => ! empty($cookies['csrftoken']),
                'has_session' => ! empty($cookies['sessionid']),
            ]);

            IntegrationLog::record('emis_gtk', 'emis_gtk_cookies.received', 'success', [
                'has_csrf' => ! empty($cookies['csrftoken']),
                'has_session' => ! empty($cookies['sessionid']),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cookie GTK diterima! Data guru & staf siap disinkronisasi.',
                'ttl_seconds' => (int) env('EMIS_GTK_TOKEN_TTL', 28800),
            ]);
        }

        // Mode lama / fallback: kirim token sebagai raw string
        $request->validate([
            'token' => 'required|string|min:20',
        ]);

        $token = $request->input('token');
        $this->emis->storeGtkCookies(['raw_token' => $token]);

        Log::info('[API] Token EMIS GTK (legacy) diterima dari bookmarklet', ['ip' => $request->ip()]);

        return response()->json([
            'success' => true,
            'message' => 'Token GTK diterima! Data guru & staf siap disinkronisasi.',
        ]);
    }

    // ── Status Token ──────────────────────────────────────────────────

    /**
     * GET /api/emis/status
     * Cek apakah token EMIS aktif (untuk ditampilkan di UI).
     */
    public function tokenStatus(): JsonResponse
    {
        return response()->json([
            'emis_siswa' => [
                'active' => $this->emis->hasActiveEmisToken(),
                'message' => $this->emis->hasActiveEmisToken()
                    ? 'Token aktif — sinkronisasi siap dijalankan'
                    : 'Token tidak tersedia atau kadaluwarsa — klik bookmarklet',
                'pull_progress' => Cache::get('emis:pull:progress', null),
                'commit_progress' => Cache::get('emis:commit:progress', null),
            ],
        ]);
    }

    /**
     * GET /api/emis-gtk/status — hanya status domain GTK (tanpa kredensial / status siswa).
     */
    public function gtkTokenStatus(): JsonResponse
    {
        return response()->json([
            'emis_gtk' => [
                'active' => $this->emis->hasActiveGtkToken(),
                'message' => $this->emis->hasActiveGtkToken()
                    ? 'Token GTK aktif'
                    : 'Token GTK tidak tersedia — klik bookmarklet GTK',
                'pull_progress' => Cache::get('emis_gtk:pull:progress', null),
            ],
        ]);
    }

    // ── Trigger Sinkronisasi Manual ───────────────────────────────────

    /**
     * POST /api/emis/pull
     * Trigger penarikan data manual (jika token masih aktif di cache).
     */
    public function triggerPull(Request $request): JsonResponse
    {
        if (! $this->emis->hasActiveEmisToken()) {
            return response()->json([
                'success' => false,
                'message' => 'Token EMIS tidak tersedia atau sudah kadaluwarsa. Silakan klik bookmarklet terlebih dahulu.',
            ], 422);
        }

        $clearStaging = filter_var($request->input('clear_staging', false), FILTER_VALIDATE_BOOLEAN);

        if ($clearStaging) {
            if ($denied = $this->requireStagingWriteForClear($request)) {
                return $denied;
            }
            // Hanya baris belum-commit (pending) yang dibersihkan; riwayat committed dipertahankan.
            $removed = EmisStaging::query()->where('status', 'pending')->delete();
            $this->auditStagingClear($request, 'student', $removed);
            Cache::forget('emis:pull:progress');
        }

        // Hapus flag pembatalan sebelum menjalankan job baru
        Cache::forget('emis:sync:cancel');

        PullEmisStudentDataJob::dispatch()->onQueue('emis');

        IntegrationLog::record('emis_siswa', 'emis_siswa.pull_triggered', 'success', [
            'clear_staging' => $clearStaging,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Penarikan data siswa dimulai. Silakan tunggu hingga selesai.',
        ]);
    }

    /**
     * POST /api/emis/cancel-sync
     * Batalkan sinkronisasi yang sedang berjalan
     */
    public function cancelSync(Request $request): JsonResponse
    {
        // Beri tanda ke worker agar berhenti (jika didukung)
        Cache::put('emis:sync:cancel', true, now()->addMinutes(5));

        // Hapus cache progress agar UI langsung berhenti
        Cache::forget('emis:sync:progress');

        return response()->json([
            'success' => true,
            'message' => 'Perintah pembatalan sinkronisasi telah dikirim.',
        ]);
    }

    /**
     * GET /api/emis/staging
     * Ambil data dari tabel staging untuk direview di frontend
     */
    public function stagingList(Request $request): JsonResponse
    {
        $paginator = EmisStaging::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->paginate($this->resolvePerPage($request, 50, 200));

        // Bulk lookup: satu query untuk seluruh halaman (tanpa N+1)
        $nisns = $paginator->getCollection()->pluck('nisn')->filter()->unique()->values()->all();
        $existingByNisn = $nisns === []
            ? collect()
            : Student::withTrashed()->whereIn('nisn', $nisns)->get()->unique('nisn')->keyBy('nisn');

        $results = $paginator->getCollection()->map(function ($staging) use ($existingByNisn) {
            $existing = $existingByNisn->get($staging->nisn);

            return [
                'id' => $staging->id,
                'nisn' => $staging->nisn,
                'name' => $staging->name,
                'rombel_name' => $staging->rombel_name,
                'match_status' => $existing ? 'update' : 'new',
                'existing_name' => $existing?->name,
                'is_protected' => $existing ? $existing->isIdentityFieldProtected() : false,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $this->sanitizeStagingRows($request, $results->all(), ['nisn', 'name', 'existing_name']),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * POST /api/emis/commit
     * Eksekusi upsert dari staging ke tabel students utama (hanya diizinkan pada mode development/migration
     * atau melalui prosedur break-glass terotorisasi).
     */
    public function commitStaging(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeCommitRequest($request, 'emis_siswa', 'student')) {
            return $denied;
        }

        $allowOverwrite = filter_var($request->input('allow_overwrite', false), FILTER_VALIDATE_BOOLEAN);
        $overwriteConfirmed = filter_var($request->input('overwrite_confirmed', false), FILTER_VALIDATE_BOOLEAN);
        $isBreakGlass = filter_var($request->input('break_glass', false), FILTER_VALIDATE_BOOLEAN);

        if ($allowOverwrite && ! $overwriteConfirmed) {
            return response()->json([
                'success' => false,
                'code' => 'OVERWRITE_CONFIRMATION_REQUIRED',
                'message' => 'Import ulang yang menimpa data master PINTU wajib menyertakan konfirmasi eksplisit (overwrite_confirmed=true).',
                'error' => ['code' => 'OVERWRITE_CONFIRMATION_REQUIRED'],
            ], 422);
        }

        $activeYear = AcademicYear::active();
        if (! $activeYear) {
            return response()->json(['success' => false, 'message' => 'Tahun Ajaran Aktif belum diatur.'], 422);
        }

        Cache::forget('emis:commit:progress');

        CommitEmisStagingJob::dispatch(
            actorId: $request->user()?->id,
            allowOverwrite: $allowOverwrite,
            overwriteConfirmed: $overwriteConfirmed,
            isBreakGlass: $isBreakGlass,
        )->onQueue('emis');

        IntegrationLog::record('emis_siswa', 'emis_siswa.commit_triggered', 'success', [
            'academic_year_id' => $activeYear->id,
            'allow_overwrite' => $allowOverwrite,
            'overwrite_confirmed' => $overwriteConfirmed,
            'lifecycle_mode' => $this->ownership->lifecycleMode(),
            'is_break_glass' => $isBreakGlass,
        ]);

        return response()->json([
            'success' => true,
            'break_glass' => $isBreakGlass,
            'message' => 'Menyimpan data siswa dan menempatkan ke rombel...',
        ]);
    }

    private function parseGradeLevel(string $rombelName): string
    {
        $name = strtoupper($rombelName);

        // Match roman numerals VII, VIII, IX, X, XI, XII
        if (preg_match('/(?:KELAS\s*)?(VII|VIII|IX|X|XI|XII)/', $name, $matches)) {
            return $matches[1];
        }

        // Match numbers 7, 8, 9, 10, 11, 12
        if (preg_match('/(?:KELAS\s*)?([789]|10|11|12)/', $name, $matches)) {
            $map = ['7' => 'VII', '8' => 'VIII', '9' => 'IX', '10' => 'X', '11' => 'XI', '12' => 'XII'];

            return $map[$matches[1]] ?? 'VII';
        }

        return 'VII'; // Default fallback
    }
    // ── GTK Sync Methods ──────────────────────────────────────────────

    public function triggerGtkPull(Request $request): JsonResponse
    {
        if (! $this->emis->hasActiveGtkToken()) {
            return response()->json([
                'success' => false,
                'message' => 'Token GTK tidak tersedia atau sudah kadaluwarsa. Silakan klik bookmarklet GTK terlebih dahulu.',
            ], 422);
        }

        $clearStaging = filter_var($request->input('clear_staging', false), FILTER_VALIDATE_BOOLEAN);

        if ($clearStaging) {
            if ($denied = $this->requireStagingWriteForClear($request)) {
                return $denied;
            }
            $removed = EmisGtkStaging::query()->where('status', 'pending')->delete();
            $this->auditStagingClear($request, 'staff', $removed);
            Cache::forget('emis_gtk:pull:progress');
        }

        PullEmisGtkDataJob::dispatch()->onQueue('emis');

        return response()->json([
            'success' => true,
            'message' => 'Penarikan data GTK dimulai. Silakan tunggu hingga selesai.',
        ]);
    }

    public function gtkStagingList(Request $request): JsonResponse
    {
        $paginator = EmisGtkStaging::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->paginate($this->resolvePerPage($request, 50, 200));

        $page = $paginator->getCollection();

        // Bulk lookup identitas GTK untuk seluruh halaman (tanpa N+1)
        $columns = ['nik', 'nuptk', 'nip', 'npk'];
        $values = [];
        foreach ($columns as $col) {
            $values[$col] = $page->pluck($col)->filter()->unique()->values()->all();
        }
        $existingRows = collect();
        if (array_filter($values) !== []) {
            $existingRows = Staff::withTrashed()
                ->where(function ($q) use ($values) {
                    foreach ($values as $col => $list) {
                        if ($list !== []) {
                            $q->orWhereIn($col, $list);
                        }
                    }
                })
                ->get();
        }

        $results = $page->map(function ($staging) use ($existingRows, $columns) {
            $existing = null;
            foreach ($columns as $col) {
                if ($staging->{$col} && ($existing = $existingRows->firstWhere($col, $staging->{$col}))) {
                    break;
                }
            }

            return [
                'id' => $staging->id,
                'full_name_with_titles' => $staging->full_name_with_titles,
                'nik' => $staging->nik,
                'employment_status' => $staging->employment_status,
                'match_status' => $existing ? 'update' : 'new',
                'existing_name' => $existing?->full_name_with_titles,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $this->sanitizeStagingRows($request, $results->all(), ['full_name_with_titles', 'existing_name']),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function commitGtkStaging(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeCommitRequest($request, 'emis_gtk', 'staff')) {
            return $denied;
        }

        $allowOverwrite = filter_var($request->input('allow_overwrite', false), FILTER_VALIDATE_BOOLEAN);
        $overwriteConfirmed = filter_var($request->input('overwrite_confirmed', false), FILTER_VALIDATE_BOOLEAN);
        $isBreakGlass = filter_var($request->input('break_glass', false), FILTER_VALIDATE_BOOLEAN);

        if ($allowOverwrite && ! $overwriteConfirmed) {
            return response()->json([
                'success' => false,
                'message' => 'Import ulang yang menimpa data master GTK PINTU wajib menyertakan konfirmasi eksplisit (overwrite_confirmed=true).',
                'error' => ['code' => 'OVERWRITE_CONFIRMATION_REQUIRED'],
            ], 422);
        }

        Cache::forget('emis_gtk:commit:progress');

        CommitEmisGtkStagingJob::dispatch(
            actorId: $request->user()?->id,
            allowOverwrite: $allowOverwrite,
            overwriteConfirmed: $overwriteConfirmed,
            isBreakGlass: $isBreakGlass,
        )->onQueue('emis');

        IntegrationLog::record('emis_gtk', 'emis_gtk.commit_triggered', 'success', [
            'allow_overwrite' => $allowOverwrite,
            'overwrite_confirmed' => $overwriteConfirmed,
            'lifecycle_mode' => $this->ownership->lifecycleMode(),
            'is_break_glass' => $isBreakGlass,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Menyimpan data GTK ke database utama...',
        ]);
    }

    /**
     * POST /api/emis/reconcile
     * Rekonsiliasi non-destruktif data Siswa antara master PINTU (SSOT) dan staging EMIS.
     */
    public function reconcileStudents(Request $request): JsonResponse
    {
        $batch = $this->reconciliation->reconcileStudents(
            actorId: $request->user()?->id,
            notes: $request->input('notes'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Rekonsiliasi data Siswa selesai tanpa mengubah master PINTU.',
            'batch' => $this->batchSummary($batch),
            'data' => $this->batchSummary($batch),
        ]);
    }

    /**
     * POST /api/emis-gtk/reconcile
     * Rekonsiliasi non-destruktif data GTK antara master PINTU (SSOT) dan staging EMIS GTK.
     */
    public function reconcileGtk(Request $request): JsonResponse
    {
        $batch = $this->reconciliation->reconcileStaff(
            actorId: $request->user()?->id,
            notes: $request->input('notes'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Rekonsiliasi data GTK selesai tanpa mengubah master PINTU.',
            'batch' => $this->batchSummary($batch),
            'data' => $this->batchSummary($batch),
        ]);
    }

    /**
     * Ringkasan batch rekonsiliasi tanpa relasi `items` (berisi snapshot PII).
     * Item diakses lewat endpoint /reconciliation-batches/{batch}/items yang tersanitasi.
     *
     * @return array<string, mixed>
     */
    private function batchSummary(EmisImportBatch $batch): array
    {
        $batch->unsetRelation('items');
        $summary = $batch->toArray();
        unset($summary['items']);
        $summary['items_count'] = $batch->items()->count();

        return $summary;
    }

    /**
     * GET /api/emis/reconciliation-batches | GET /api/emis-gtk/reconciliation-batches
     */
    public function reconciliationBatches(Request $request): JsonResponse
    {
        $boundEntityType = $this->resolveBoundEntityType($request);
        if ($denied = $this->ensureEntityScopeMatches($request, $boundEntityType)) {
            return $denied;
        }

        $query = EmisImportBatch::query()
            ->withCount('items')
            ->where('entity_type', $boundEntityType)
            ->orderByDesc('created_at');

        if ($request->filled('process_type')) {
            $query->where('process_type', $request->input('process_type'));
        }

        $paginator = $query->paginate($this->resolvePerPage($request, 20, 50));

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * GET /api/emis/reconciliation-batches/{batch}/items | GET /api/emis-gtk/reconciliation-batches/{batch}/items
     * Item rekonsiliasi per batch (paginasi), dengan PII disanitasi sesuai level autentikasi.
     */
    public function reconciliationBatchItems(Request $request, EmisImportBatch $batch): JsonResponse
    {
        $boundEntityType = $this->resolveBoundEntityType($request);
        if ($denied = $this->ensureEntityScopeMatches($request, $boundEntityType, $batch->entity_type)) {
            return $denied;
        }

        $query = $batch->items()->orderBy('id');

        if ($request->filled('classification')) {
            $query->where('classification', $request->input('classification'));
        }

        $paginator = $query->paginate($this->resolvePerPage($request, 50, 200));
        $level = $this->piiLevel($request);

        $items = collect($paginator->items())
            ->map(fn ($item): array => $this->pii->sanitizeItem($item->toArray(), $level))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * GET /api/emis/update-tasks | GET /api/emis-gtk/update-tasks
     * Daftar perubahan master PINTU yang perlu diperbarui oleh operator ke EMIS.
     */
    public function updateTasks(Request $request): JsonResponse
    {
        $boundEntityType = $this->resolveBoundEntityType($request);
        if ($denied = $this->ensureEntityScopeMatches($request, $boundEntityType)) {
            return $denied;
        }

        $query = EmisUpdateTask::query()
            ->where('entity_type', $boundEntityType)
            ->orderByDesc('created_at');

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->input('entity_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->paginate($this->resolvePerPage($request, 50, 100));
        $level = $this->piiLevel($request);

        $tasks = collect($paginator->items())
            ->map(fn (EmisUpdateTask $task): array => $this->pii->sanitize($task->toArray(), $level))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $tasks,
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * PATCH /api/emis/update-tasks/{task} | PATCH /api/emis-gtk/update-tasks/{task}
     * Operator EMIS mencatat status pembaruan eksternal di EMIS tanpa mengubah master PINTU.
     */
    public function updateTaskStatus(Request $request, EmisUpdateTask $task): JsonResponse
    {
        if (
            $request->attributes->get('integration_auth_method') === 'token'
            || $request->attributes->get('integration_token') !== null
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Token integrasi tidak diizinkan mengubah status tugas pembaruan EMIS.',
                'error' => ['code' => 'INTEGRATION_TOKEN_TASK_UPDATE_FORBIDDEN'],
            ], 403);
        }

        $user = $request->user();
        if (! $user || ! $user->can('sync_emis')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya operator terautentikasi dengan izin sync_emis yang dapat memperbarui status sinkronisasi EMIS.',
                'error' => ['code' => 'FORBIDDEN_PERMISSION'],
            ], 403);
        }

        $boundEntityType = $this->resolveBoundEntityType($request);
        if ($denied = $this->ensureEntityScopeMatches($request, $boundEntityType, $task->entity_type)) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(EmisUpdateTask::VALID_STATUSES)],
            'operator_notes' => ['nullable', 'string', 'max:2000'],
            'confirmation_reference' => ['nullable', 'string', 'max:255'],
            'reopen_confirmed' => ['nullable', 'boolean'],
        ]);

        // Tugas yang sudah completed tidak boleh dibuka kembali secara sembarangan tanpa alasan & konfirmasi eksplisit.
        if (
            $task->status === EmisUpdateTask::STATUS_COMPLETED
            && $validated['status'] !== EmisUpdateTask::STATUS_COMPLETED
        ) {
            $reopenConfirmed = filter_var($request->input('reopen_confirmed', false), FILTER_VALIDATE_BOOLEAN);
            $notes = trim((string) ($validated['operator_notes'] ?? ''));

            if (! $reopenConfirmed || mb_strlen($notes) < 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tugas sinkronisasi EMIS yang sudah selesai (completed) tidak dapat dibuka kembali tanpa konfirmasi eksplisit (reopen_confirmed=true) dan alasan minimal 10 karakter.',
                    'error' => ['code' => 'INVALID_TASK_STATUS_TRANSITION'],
                ], 422);
            }
        }

        $updated = $this->reconciliation->updateTaskStatus(
            task: $task,
            status: $validated['status'],
            operatorNotes: $validated['operator_notes'] ?? null,
            confirmationReference: $validated['confirmation_reference'] ?? null,
            actor: $user,
        );

        return response()->json([
            'success' => true,
            'message' => 'Status pembaruan EMIS berhasil dicatat.',
            'data' => $updated,
        ]);
    }

    /**
     * Validasi otorisasi & kebijakan siklus hidup sistem sebelum mengizinkan commit EMIS ke master PINTU.
     */
    private function authorizeCommitRequest(Request $request, string $purpose, string $entityType): ?JsonResponse
    {
        if (
            $request->attributes->get('integration_auth_method') === 'token'
            || $request->attributes->get('integration_token') !== null
        ) {
            IntegrationLog::record($purpose, "{$purpose}.commit_rejected", 'failed', [
                'reason' => 'integration_token_forbidden',
                'entity_type' => $entityType,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token integrasi tidak diizinkan memicu mutasi data master PINTU.',
                'error' => ['code' => 'INTEGRATION_TOKEN_COMMIT_FORBIDDEN'],
            ], 403);
        }

        $user = $request->user();
        if (! $user || ! $user->can('sync_emis')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin (sync_emis) untuk mengeksekusi commit data EMIS.',
                'error' => ['code' => 'FORBIDDEN_PERMISSION'],
            ], 403);
        }

        if (! $this->ownership->isProductionMode()) {
            return null;
        }

        $isBreakGlass = filter_var($request->input('break_glass', false), FILTER_VALIDATE_BOOLEAN);

        if (! $isBreakGlass) {
            ActivityLog::record('emis_commit_blocked_in_production', null, after: [
                'purpose' => $purpose,
                'entity_type' => $entityType,
                'lifecycle_mode' => $this->ownership->lifecycleMode(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Mutasi data master PINTU dari EMIS dinonaktifkan pada mode produksi. Gunakan endpoint rekonsiliasi non-destruktif.',
                'error' => ['code' => 'EMIS_COMMIT_DISABLED_IN_PRODUCTION'],
            ], 403);
        }

        if (! $this->ownership->isBreakGlassEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Prosedur break-glass migrasi EMIS tidak diaktifkan pada lingkungan produksi ini.',
                'error' => ['code' => 'BREAK_GLASS_DISABLED'],
            ], 403);
        }

        $requiredPermission = $this->ownership->breakGlassPermission();
        if (! $user->can($requiredPermission)) {
            return response()->json([
                'success' => false,
                'message' => "Prosedur break-glass membutuhkan izin khusus ({$requiredPermission}).",
                'error' => ['code' => 'FORBIDDEN_BREAK_GLASS_PERMISSION'],
            ], 403);
        }

        $confirmed = filter_var($request->input('break_glass_confirmed', false), FILTER_VALIDATE_BOOLEAN);
        $reason = trim((string) $request->input('break_glass_reason', ''));
        $minLength = $this->ownership->breakGlassMinReasonLength();

        if (! $confirmed || mb_strlen($reason) < $minLength) {
            return response()->json([
                'success' => false,
                'message' => "Break-glass wajib menyertakan konfirmasi (break_glass_confirmed=true) dan alasan tertulis minimal {$minLength} karakter.",
                'error' => ['code' => 'BREAK_GLASS_REASON_REQUIRED'],
            ], 422);
        }

        ActivityLog::record('emis_break_glass_commit_authorized', null, after: [
            'purpose' => $purpose,
            'entity_type' => $entityType,
            'break_glass_reason' => $reason,
            'allow_overwrite' => filter_var($request->input('allow_overwrite', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        IntegrationLog::record($purpose, "{$purpose}.break_glass_authorized", 'success', [
            'user_id' => $user->id,
            'entity_type' => $entityType,
            'reason' => $reason,
        ]);

        return null;
    }

    /**
     * Tentukan domain entitas ('student' atau 'staff') berdasarkan rute / purpose token.
     */
    private function resolveBoundEntityType(Request $request): string
    {
        $purpose = $request->attributes->get('integration_purpose');
        if ($purpose === 'emis_gtk' || str_starts_with($request->path(), 'api/emis-gtk')) {
            return 'staff';
        }

        return 'student';
    }

    /**
     * Tolak permintaan lintas domain antara EMIS Siswa dan EMIS GTK.
     */
    private function ensureEntityScopeMatches(Request $request, string $boundEntityType, ?string $targetEntityType = null): ?JsonResponse
    {
        if ($request->filled('entity_type') && (string) $request->input('entity_type') !== $boundEntityType) {
            return response()->json([
                'success' => false,
                'message' => "Endpoint ini terikat pada domain '{$boundEntityType}' dan tidak mengizinkan akses ke '{$request->input('entity_type')}'.",
                'error' => ['code' => 'FORBIDDEN_ENTITY_SCOPE'],
            ], 403);
        }

        if ($targetEntityType !== null && $targetEntityType !== $boundEntityType) {
            return response()->json([
                'success' => false,
                'message' => "Resource milik domain '{$targetEntityType}' tidak dapat diakses melalui endpoint domain '{$boundEntityType}'.",
                'error' => ['code' => 'FORBIDDEN_ENTITY_SCOPE'],
            ], 403);
        }

        return null;
    }

    /**
     * Simpan item import GTK ke staging dengan penanda sesi import.
     */
    private function storeGtkImportItems(array $items, string $sessionId): int
    {
        $saved = 0;
        foreach ($items as $item) {
            $name = mb_strtoupper(trim($item['name'] ?? ''));
            if (empty($name)) {
                continue;
            }

            $uuid = $item['uuid'] ?? null;
            $identity = $uuid
                ? ['emis_gtk_id' => $uuid]
                : $this->gtkIdentity($item, $name);

            $data = [
                'emis_gtk_id' => $uuid,
                'full_name_with_titles' => $name,
                'nik' => $item['nik'] ?? null,
                'nip' => $item['nip'] ?? null,
                'npk' => $item['npk'] ?? null,
                'nuptk' => $item['nuptk'] ?? null,
                'nrg' => $item['nrg'] ?? null,
                'peg_id' => $item['peg_id'] ?? null,
                'function_role' => $item['fungsi'] ?? $item['function_role'] ?? null,
                'employment_status' => $item['employment'] ?? $item['employment_status'] ?? null,
                'source' => 'bookmarklet',
                'last_seen_at' => now(),
                'raw_data' => $item,
                'status' => 'pending',
            ];

            if (array_key_exists('active', $item)) {
                $data['k_aktif'] = filter_var($item['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (array_key_exists('is_sertifikasi', $item)) {
                $data['is_sertifikasi'] = filter_var($item['is_sertifikasi'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            $data['import_session_id'] = $sessionId;
            $existing = EmisGtkStaging::query()->where($identity)->where('status', '!=', 'committed')->first();
            if ($existing) {
                $existing->update($data);
            } else {
                EmisGtkStaging::create($identity + $data);
            }
            $saved++;
        }

        return $saved;
    }

    private function requireStagingWriteForClear(Request $request): ?JsonResponse
    {
        $token = $request->attributes->get('integration_token');
        if ($this->isTokenAuth($request) && (! $token || ! $token->hasAbility('staging:write'))) {
            return response()->json([
                'success' => false,
                'message' => 'Token integrasi tidak memiliki ability untuk aksi ini.',
                'error' => ['code' => 'FORBIDDEN_TOKEN_ABILITY'],
            ], 403);
        }

        return null;
    }

    private function auditStagingClear(Request $request, string $entity, int $removed, ?string $sessionId = null): void
    {
        IntegrationLog::record(
            $entity === 'student' ? 'emis_siswa' : 'emis_gtk',
            'staging.cleared',
            'success',
            ['removed_pending_rows' => $removed, 'import_session_id' => $sessionId, 'auth_method' => $request->attributes->get('integration_auth_method')],
        );
    }

    private function isTokenAuth(Request $request): bool
    {
        return $request->attributes->get('integration_auth_method') === 'token'
            || $request->attributes->get('integration_token') !== null;
    }

    private function piiLevel(Request $request): string
    {
        return $this->isTokenAuth($request)
            ? EmisPiiSanitizer::LEVEL_INTEGRATION
            : EmisPiiSanitizer::LEVEL_OPERATOR;
    }

    /**
     * Staging list: token integrasi selalu disamarkan; operator sesi tetap melihat kolom
     * pengenal yang dibutuhkan untuk review ($operatorVisible), selain itu disanitasi.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $operatorVisible
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeStagingRows(Request $request, array $rows, array $operatorVisible): array
    {
        $isToken = $this->isTokenAuth($request);

        return array_map(function (array $row) use ($isToken, $operatorVisible): array {
            $kept = $isToken ? [] : array_intersect_key($row, array_flip($operatorVisible));

            return array_merge(
                $this->pii->sanitize($row, EmisPiiSanitizer::LEVEL_INTEGRATION),
                $kept
            );
        }, $rows);
    }

    private function resolvePerPage(Request $request, int $default, int $max): int
    {
        return max(1, min($max, (int) $request->input('per_page', $default)));
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * Tentukan kunci identitas staging dari data bookmarklet.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function gtkIdentity(array $item, string $name): array
    {
        foreach (['nik', 'nuptk', 'nip', 'npk'] as $column) {
            if (filled($item[$column] ?? null)) {
                return [$column => $item[$column]];
            }
        }

        return ['full_name_with_titles' => $name];
    }

    /**
     * POST /api/emis-gtk/import
     *
     * Endpoint baru: menerima data GTK yang sudah di-scrape langsung
     * oleh bookmarklet dari browser. Bookmarklet berjalan di konteks
     * emisgtk.kemenag.go.id sehingga cookies otomatis disertakan
     * saat fetch ke EMIS, lalu hasilnya dikirim ke sini.
     *
     * Payload: {
     *   data: [{name, uuid, phone, active}, ...],
     *   page: 1,
     *   total_pages: 5,
     *   total_count: 63,
     *   clear: true (hanya di page 1)
     * }
     */
    public function importGtkData(Request $request): JsonResponse
    {
        $origin = $request->header('Origin', '');
        $allowedOrigins = ['https://emisgtk.kemenag.go.id', 'http://localhost', 'http://127.0.0.1'];
        $corsOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';
        $cors = [
            'Access-Control-Allow-Origin' => $corsOrigin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, X-Requested-With',
        ];

        $request->validate([
            'data' => 'required|array|min:1',
            'data.*.name' => 'required|string',
            'page' => 'sometimes|integer|min:1',
            'total_pages' => 'sometimes|integer|min:1|max:1000',
            'total_count' => 'sometimes|nullable|integer|min:0',
            'import_session_id' => 'sometimes|nullable|string|max:64',
        ]);

        $items = $request->input('data', []);
        $page = (int) $request->input('page', 1);
        $totalPages = (int) $request->input('total_pages', 1);
        $totalCount = $request->filled('total_count') ? (int) $request->input('total_count') : null;
        $sessionId = $request->input('import_session_id');
        $ownerKey = $this->importOwnerKey($request);

        if ($page > $totalPages) {
            return $this->importError('INVALID_PAGE', 'Nomor halaman melebihi total_pages.', 422, $cors);
        }

        if (blank($sessionId)) {
            if ($page !== 1) {
                return $this->importError('IMPORT_SESSION_REQUIRED', 'Halaman > 1 wajib menyertakan import_session_id dari respons halaman pertama.', 422, $cors);
            }
            $session = EmisImportSession::create([
                'entity_type' => 'staff',
                'status' => EmisImportSession::STATUS_OPEN,
                'owner_key' => $ownerKey,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'source' => 'bookmarklet',
                'started_at' => now(),
                'expires_at' => now()->addHours(2),
            ]);
        } else {
            $session = EmisImportSession::query()->where('id', $sessionId)->where('entity_type', 'staff')->first();
            if (! $session || $session->owner_key !== $ownerKey) {
                return $this->importError('IMPORT_SESSION_NOT_FOUND', 'Sesi import tidak ditemukan.', 404, $cors);
            }
            if ($session->status === EmisImportSession::STATUS_COMPLETED) {
                return $this->importError('IMPORT_SESSION_CLOSED', 'Sesi import sudah selesai.', 409, $cors);
            }
            if ($session->status === EmisImportSession::STATUS_EXPIRED || $session->expires_at?->isPast()) {
                $session->update(['status' => EmisImportSession::STATUS_EXPIRED]);
                EmisImportSessionPage::where('session_id', $session->id)->delete();

                return $this->importError('IMPORT_SESSION_EXPIRED', 'Sesi import kedaluwarsa. Mulai ulang dari halaman pertama.', 410, $cors);
            }
            if ($session->total_pages !== $totalPages) {
                return $this->importError('IMPORT_SESSION_MISMATCH', 'total_pages tidak sesuai dengan sesi import.', 422, $cors);
            }
        }

        // Halaman disimpan di buffer sesi (idempoten per halaman); staging aktif belum disentuh.
        EmisImportSessionPage::updateOrCreate(
            ['session_id' => $session->id, 'page' => $page],
            ['items_count' => count($items), 'payload' => $items]
        );
        $received = EmisImportSessionPage::where('session_id', $session->id);
        $session->update([
            'received_pages' => (clone $received)->count(),
            'received_rows' => (int) (clone $received)->sum('items_count'),
        ]);

        $saved = 0;
        $complete = false;
        if ($session->received_pages >= $session->total_pages) {
            $result = $this->finalizeGtkImport($request, $session);
            if ($result instanceof JsonResponse) {
                return $result->withHeaders($cors);
            }
            $saved = $result;
            $complete = true;
        }

        $progress = $totalPages > 0 ? min(100, (int) round(($session->received_pages / $totalPages) * 100)) : 100;
        Cache::put('emis_gtk:pull:progress', [
            'progress' => $progress,
            'status' => $complete
                ? "Selesai! {$session->received_rows} data GTK berhasil diterima dari bookmarklet."
                : "Menerima data halaman {$session->received_pages} dari {$totalPages}...",
        ], now()->addHours(2));

        return response()->json([
            'success' => true,
            'import_session_id' => $session->id,
            'status' => $session->fresh()->status,
            'saved' => $saved,
            'page' => $page,
            'received_pages' => $session->received_pages,
            'total_pages' => $session->total_pages,
            'complete' => $complete,
            'message' => $complete
                ? "Import selesai: {$saved} data GTK masuk staging."
                : "Halaman {$page}/{$totalPages} diterima ({$session->received_pages} halaman terkumpul).",
        ])->withHeaders($cors);
    }

    /**
     * Terapkan seluruh halaman sesi ke staging secara atomik (penggantian staging).
     */
    private function finalizeGtkImport(Request $request, EmisImportSession $session): int|JsonResponse
    {
        if ($session->total_count !== null && $session->received_rows !== $session->total_count) {
            $session->update(['failure_reason' => "Jumlah data {$session->received_rows} != total_count {$session->total_count}"]);

            return $this->importError('IMPORT_COUNT_MISMATCH', 'Jumlah data yang diterima tidak sama dengan total_count. Kirim ulang halaman yang kurang.', 422, []);
        }

        $lock = Cache::lock('emis_gtk:import:finalize', 60);
        if (! $lock->block(10)) {
            return $this->importError('IMPORT_IN_PROGRESS', 'Penggantian staging GTK lain sedang berjalan. Kirim ulang halaman terakhir sebentar lagi.', 409, []);
        }

        try {
            return DB::transaction(function () use ($session, $request) {
                $saved = 0;
                EmisImportSessionPage::where('session_id', $session->id)->orderBy('page')->each(function ($row) use ($session, &$saved) {
                    $saved += $this->storeGtkImportItems($row->payload, $session->id);
                });

                $removed = EmisGtkStaging::query()
                    ->where('status', 'pending')
                    ->where(function ($q) use ($session) {
                        $q->whereNull('import_session_id')->orWhere('import_session_id', '!=', $session->id);
                    })
                    ->delete();
                $this->auditStagingClear($request, 'staff', $removed, $session->id);

                $session->update(['status' => EmisImportSession::STATUS_COMPLETED, 'completed_at' => now(), 'failure_reason' => null]);
                EmisImportSessionPage::where('session_id', $session->id)->delete();

                return $saved;
            });
        } catch (\Throwable $e) {
            Log::error('[EMIS GTK] Finalisasi import gagal: '.$e->getMessage());
            $session->update(['failure_reason' => mb_substr($e->getMessage(), 0, 500)]);

            return $this->importError('IMPORT_FINALIZE_FAILED', 'Penggantian staging gagal; staging lama tidak berubah. Kirim ulang halaman terakhir.', 500, []);
        } finally {
            $lock->release();
        }
    }

    private function importOwnerKey(Request $request): string
    {
        $token = $request->attributes->get('integration_token');
        if ($this->isTokenAuth($request) && $token) {
            return 'token:'.$token->id;
        }

        return 'user:'.($request->user()?->id ?? 'guest');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function importError(string $code, string $message, int $status, array $headers): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => ['code' => $code],
        ], $status)->withHeaders($headers);
    }

    /**
     * GET /api/emis-gtk/progress
     * Polling progress import GTK dari bookmarklet
     */
    public function gtkProgress(): JsonResponse
    {
        $pull = Cache::get('emis_gtk:pull:progress');
        $commit = Cache::get('emis_gtk:commit:progress');
        $total = EmisGtkStaging::where('status', 'pending')->count();

        return response()->json([
            'pull_progress' => $pull,
            'commit_progress' => $commit,
            'staging_count' => $total,
        ]);
    }

    /**
     * POST /api/emis-gtk/import-raw
     * Endpoint untuk debugging: menerima raw HTML dari halaman EMIS GTK.
     */
    public function importGtkRaw(Request $request): JsonResponse
    {
        $origin = $request->header('Origin', '');
        $allowedOrigins = ['https://emisgtk.kemenag.go.id', 'http://localhost', 'http://127.0.0.1'];
        $corsOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

        $html = $request->getContent();
        File::put(storage_path('logs/emisgtk_dump.html'), $html);

        return response()->json([
            'success' => true,
            'message' => 'Raw HTML berhasil diterima untuk dianalisa.',
        ])->withHeaders([
            'Access-Control-Allow-Origin' => $corsOrigin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, X-Requested-With',
        ]);
    }
}
