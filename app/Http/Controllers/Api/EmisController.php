<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CommitEmisGtkStagingJob;
use App\Jobs\CommitEmisStagingJob;
use App\Jobs\PullEmisGtkDataJob;
use App\Jobs\PullEmisStudentDataJob;
use App\Models\AcademicYear;
use App\Models\EmisGtkStaging;
use App\Models\EmisStaging;
use App\Models\IntegrationLog;
use App\Models\Staff;
use App\Models\Student;
use App\Services\EmisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class EmisController extends Controller
{
    public function __construct(private readonly EmisService $emis) {}

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
            'emis_gtk' => [
                'active' => $this->emis->hasActiveGtkToken(),
                'message' => $this->emis->hasActiveGtkToken()
                    ? 'Token GTK aktif'
                    : 'Token GTK tidak tersedia — klik bookmarklet GTK',
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
            EmisStaging::truncate();
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
        $stagings = EmisStaging::where('status', 'pending')->get();

        // Bandingkan dengan data siswa yang ada untuk menentukan status
        $results = $stagings->map(function ($staging) {
            $existing = Student::withTrashed()->where('nisn', $staging->nisn)->first();

            $matchStatus = 'new';
            $isProtected = false;
            if ($existing) {
                // Sederhanakan komparasi: jika ada, anggap update
                $matchStatus = 'update';
                $isProtected = $existing->isIdentityFieldProtected();
            }

            return [
                'id' => $staging->id,
                'nisn' => $staging->nisn,
                'name' => $staging->name,
                'rombel_name' => $staging->rombel_name,
                'match_status' => $matchStatus, // new, update
                'existing_name' => $existing ? $existing->name : null,
                'is_protected' => $isProtected,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * POST /api/emis/commit
     * Eksekusi upsert dari staging ke tabel students utama
     */
    public function commitStaging(Request $request): JsonResponse
    {
        $activeYear = AcademicYear::active();
        if (! $activeYear) {
            return response()->json(['success' => false, 'message' => 'Tahun Ajaran Aktif belum diatur.'], 422);
        }

        Cache::forget('emis:commit:progress');

        CommitEmisStagingJob::dispatch()->onQueue('emis');

        IntegrationLog::record('emis_siswa', 'emis_siswa.commit_triggered', 'success', [
            'academic_year_id' => $activeYear->id,
        ]);

        return response()->json([
            'success' => true,
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
            EmisGtkStaging::truncate();
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
        $stagings = EmisGtkStaging::where('status', 'pending')->get();

        $results = $stagings->map(function ($staging) {
            $existing = Staff::withTrashed()
                ->where('nik', $staging->nik)
                ->orWhere(function ($q) use ($staging) {
                    if ($staging->nuptk) {
                        $q->orWhere('nuptk', $staging->nuptk);
                    }
                    if ($staging->nip) {
                        $q->orWhere('nip', $staging->nip);
                    }
                    if ($staging->npk) {
                        $q->orWhere('npk', $staging->npk);
                    }
                })
                ->first();

            $matchStatus = 'new';
            if ($existing) {
                $matchStatus = 'update';
            }

            return [
                'id' => $staging->id,
                'full_name_with_titles' => $staging->full_name_with_titles,
                'nik' => $staging->nik,
                'employment_status' => $staging->employment_status,
                'match_status' => $matchStatus,
                'existing_name' => $existing ? $existing->full_name_with_titles : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    public function commitGtkStaging(Request $request): JsonResponse
    {
        Cache::forget('emis_gtk:commit:progress');

        CommitEmisGtkStagingJob::dispatch()->onQueue('emis');

        IntegrationLog::record('emis_gtk', 'emis_gtk.commit_triggered', 'success');

        return response()->json([
            'success' => true,
            'message' => 'Menyimpan data GTK ke database utama...',
        ]);
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
        // Allow CORS dari emisgtk.kemenag.go.id
        $origin = $request->header('Origin', '');
        $allowedOrigins = ['https://emisgtk.kemenag.go.id', 'http://localhost', 'http://127.0.0.1'];
        $corsOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

        $request->validate([
            'data' => 'required|array|min:1',
            'data.*.name' => 'required|string',
            'page' => 'sometimes|integer|min:1',
        ]);

        $items = $request->input('data', []);
        $page = (int) $request->input('page', 1);
        $totalPages = (int) $request->input('total_pages', 1);
        $totalCount = (int) $request->input('total_count', count($items));
        $clear = filter_var($request->input('clear', false), FILTER_VALIDATE_BOOLEAN);

        // Hapus staging lama jika di halaman pertama atau ini adalah sync baru
        if ($page === 1) {
            EmisGtkStaging::truncate();
        }

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

            EmisGtkStaging::updateOrCreate($identity, $data);
            $saved++;
        }

        // Simpan progress ke cache untuk polling UI
        $progress = $totalPages > 0 ? min(100, (int) round(($page / $totalPages) * 100)) : 100;
        Cache::put('emis_gtk:pull:progress', [
            'progress' => $progress,
            'status' => $progress >= 100
                ? "Selesai! {$totalCount} data GTK berhasil dikirim dari bookmarklet."
                : "Menerima data halaman {$page} dari {$totalPages}...",
        ], now()->addHours(2));

        Log::info("[EMIS GTK] Import via bookmarklet: page={$page}/{$totalPages}, saved={$saved}");

        return response()->json([
            'success' => true,
            'saved' => $saved,
            'page' => $page,
            'message' => "Halaman {$page}/{$totalPages}: {$saved} data diterima.",
        ])->withHeaders([
            'Access-Control-Allow-Origin' => $corsOrigin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, X-Requested-With',
        ]);
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
