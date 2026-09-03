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

        // Trigger job tarik data di background
        PullEmisStudentDataJob::dispatch()->onQueue('emis');

        Log::info('[API] Token EMIS siswa diterima dari bookmarklet', [
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Token diterima! Data siswa sedang ditarik di latar belakang.',
            'ttl_seconds' => (int) env('EMIS_TOKEN_TTL', 7200),
        ]);
    }

    // ── Terima Token EMIS GTK dari Bookmarklet ────────────────────────

    /**
     * POST /api/emis-gtk/token
     * Menerima JWT token EMIS GTK dari bookmarklet.
     */
    public function receiveGtkToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|min:20',
        ]);

        $token = $request->input('token');

        if (substr_count($token, '.') !== 2) {
            return response()->json(['success' => false, 'message' => 'Format token tidak valid.'], 422);
        }

        $this->emis->storeGtkToken($token);

        Log::info('[API] Token EMIS GTK diterima dari bookmarklet', ['ip' => $request->ip()]);

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

        PullEmisStudentDataJob::dispatch()->onQueue('emis');

        return response()->json([
            'success' => true,
            'message' => 'Sinkronisasi data siswa dimulai di latar belakang.',
        ]);
    }
}
