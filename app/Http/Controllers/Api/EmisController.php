<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmisService;
use App\Jobs\PullEmisStudentDataJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'token'  => 'required|string|min:20',
            'source' => 'sometimes|string',
        ]);

        $token = $request->input('token');

        // Validasi format JWT (3 bagian dipisah titik)
        if (substr_count($token, '.') !== 2) {
            return response()->json([
                'success' => false,
                'message' => 'Format token tidak valid. Pastikan sudah login ke EMIS.',
            ], 422);
        }

        $this->emis->storeEmisToken($token);

        Log::info('[API] Token EMIS siswa diterima dari bookmarklet', [
            'ip' => $request->ip(),
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
                'has_csrf' => !empty($cookies['csrftoken']),
                'has_session' => !empty($cookies['sessionid']),
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
                'active'  => $this->emis->hasActiveEmisToken(),
                'message' => $this->emis->hasActiveEmisToken()
                    ? 'Token aktif — sinkronisasi siap dijalankan'
                    : 'Token tidak tersedia atau kadaluwarsa — klik bookmarklet',
                'pull_progress'  => \Illuminate\Support\Facades\Cache::get('emis:pull:progress', null),
                'commit_progress'=> \Illuminate\Support\Facades\Cache::get('emis:commit:progress', null),
            ],
            'emis_gtk' => [
                'active'  => $this->emis->hasActiveGtkToken(),
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
            \App\Models\EmisStaging::truncate();
            \Illuminate\Support\Facades\Cache::forget('emis:pull:progress');
        }

        PullEmisStudentDataJob::dispatch()->onQueue('emis');

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
        \Illuminate\Support\Facades\Cache::put('emis:sync:cancel', true, now()->addMinutes(5));
        
        // Hapus cache progress agar UI langsung berhenti
        \Illuminate\Support\Facades\Cache::forget('emis:sync:progress');

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
        $stagings = \App\Models\EmisStaging::where('status', 'pending')->get();

        // Bandingkan dengan data siswa yang ada untuk menentukan status
        $results = $stagings->map(function ($staging) {
            $existing = \App\Models\Student::withTrashed()->where('nisn', $staging->nisn)->first();

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
        $activeYear = \App\Models\AcademicYear::active();
        if (! $activeYear) {
            return response()->json(['success' => false, 'message' => 'Tahun Ajaran Aktif belum diatur.'], 422);
        }

        \Illuminate\Support\Facades\Cache::forget('emis:commit:progress');

        \App\Jobs\CommitEmisStagingJob::dispatch()->onQueue('emis');

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
            \App\Models\EmisGtkStaging::truncate();
            \Illuminate\Support\Facades\Cache::forget('emis_gtk:pull:progress');
        }

        \App\Jobs\PullEmisGtkDataJob::dispatch()->onQueue('emis');

        return response()->json([
            'success' => true,
            'message' => 'Penarikan data GTK dimulai. Silakan tunggu hingga selesai.',
        ]);
    }

    public function gtkStagingList(Request $request): JsonResponse
    {
        $stagings = \App\Models\EmisGtkStaging::where('status', 'pending')->get();

        $results = $stagings->map(function ($staging) {
            $existing = \App\Models\Staff::withTrashed()
                ->where('nik', $staging->nik)
                ->orWhere(function ($q) use ($staging) {
                    if ($staging->nuptk) $q->orWhere('nuptk', $staging->nuptk);
                    if ($staging->nip) $q->orWhere('nip', $staging->nip);
                    if ($staging->npk) $q->orWhere('npk', $staging->npk);
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
        \Illuminate\Support\Facades\Cache::forget('emis_gtk:commit:progress');

        \App\Jobs\CommitEmisGtkStagingJob::dispatch()->onQueue('emis');

        return response()->json([
            'success' => true,
            'message' => 'Menyimpan data GTK ke database utama...',
        ]);
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
            'data'        => 'required|array|min:1',
            'data.*.name' => 'required|string',
            'page'        => 'sometimes|integer|min:1',
        ]);

        $items = $request->input('data', []);
        $page  = (int) $request->input('page', 1);
        $totalPages = (int) $request->input('total_pages', 1);
        $totalCount = (int) $request->input('total_count', count($items));
        $clear = filter_var($request->input('clear', false), FILTER_VALIDATE_BOOLEAN);

        // Hapus staging lama jika di halaman pertama atau ini adalah sync baru
        if ($page === 1) {
            \App\Models\EmisGtkStaging::truncate();
        }

        $saved = 0;
        foreach ($items as $item) {
            $name = mb_strtoupper(trim($item['name'] ?? ''));
            if (empty($name)) continue;

            $uuid = $item['uuid'] ?? null;

            $data = [
                'emis_gtk_id'           => $uuid,
                'full_name_with_titles' => $name,
                'nik'                   => $item['nik'] ?? null,
                'nip'                   => $item['nip'] ?? null,
                'npk'                   => $item['npk'] ?? null,
                'employment_status'     => $item['employment'] ?? null,
                'raw_data'              => json_encode($item),
                'status'                => 'pending',
            ];

            if ($uuid) {
                \App\Models\EmisGtkStaging::updateOrCreate(
                    ['emis_gtk_id' => $uuid],
                    $data
                );
            } else {
                \App\Models\EmisGtkStaging::updateOrCreate(
                    ['full_name_with_titles' => $name],
                    $data
                );
            }
            $saved++;
        }

        // Simpan progress ke cache untuk polling UI
        $progress = $totalPages > 0 ? min(100, (int) round(($page / $totalPages) * 100)) : 100;
        \Illuminate\Support\Facades\Cache::put('emis_gtk:pull:progress', [
            'progress'    => $progress,
            'status'      => $progress >= 100
                ? "Selesai! {$totalCount} data GTK berhasil dikirim dari bookmarklet."
                : "Menerima data halaman {$page} dari {$totalPages}...",
        ], now()->addHours(2));

        Log::info("[EMIS GTK] Import via bookmarklet: page={$page}/{$totalPages}, saved={$saved}");

        return response()->json([
            'success' => true,
            'saved'   => $saved,
            'page'    => $page,
            'message' => "Halaman {$page}/{$totalPages}: {$saved} data diterima.",
        ])->withHeaders([
            'Access-Control-Allow-Origin'  => $corsOrigin,
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
        $pull   = \Illuminate\Support\Facades\Cache::get('emis_gtk:pull:progress');
        $commit = \Illuminate\Support\Facades\Cache::get('emis_gtk:commit:progress');
        $total  = \App\Models\EmisGtkStaging::where('status', 'pending')->count();

        return response()->json([
            'pull_progress'   => $pull,
            'commit_progress' => $commit,
            'staging_count'   => $total,
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
        \Illuminate\Support\Facades\File::put(storage_path('logs/emisgtk_dump.html'), $html);

        return response()->json([
            'success' => true,
            'message' => 'Raw HTML berhasil diterima untuk dianalisa.',
        ])->withHeaders([
            'Access-Control-Allow-Origin'  => $corsOrigin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, X-Requested-With',
        ]);
    }
}
