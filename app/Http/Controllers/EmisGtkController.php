<?php

namespace App\Http\Controllers;

use App\Jobs\RunEmisGtkSyncJob;
use App\Models\EmisGtkStaging;
use App\Models\Setting;
use App\Services\EmisGtkScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class EmisGtkController extends Controller
{
    private const SYNC_CACHE_KEY = 'emis_gtk_sync_status';

    /**
     * Halaman integrasi EMIS GTK.
     */
    public function index(): Response
    {
        $lastSync  = Setting::get('emis_gtk_last_sync');
        $stagings  = EmisGtkStaging::orderBy('name')->paginate(100);
        $hasSsoToken = filled(Setting::get('emis_sso_token'));

        $summary = [
            'total'        => EmisGtkStaging::count(),
            'sertifikasi'  => EmisGtkStaging::where('is_sertifikasi', true)->count(),
            'aktif'        => EmisGtkStaging::where('k_aktif', true)->count(),
            'last_sync'    => $lastSync,
            'has_sso'      => $hasSsoToken,
        ];

        return Inertia::render('Emis/Gtk', [
            'stagings' => $stagings,
            'summary'  => $summary,
        ]);
    }

    /**
     * Mulai sinkronisasi EMIS GTK secara otomatis via SSO token.
     * Dijadikan background job agar tidak timeout.
     */
    public function sync(Request $request): JsonResponse|RedirectResponse
    {
        // Cek apakah sudah ada sync berjalan
        if (Cache::has(self::SYNC_CACHE_KEY . '.running')) {
            return response()->json([
                'success' => false,
                'message' => 'Sinkronisasi sedang berjalan. Harap tunggu...',
            ], 409);
        }

        Cache::put(self::SYNC_CACHE_KEY . '.running', true, 300); // 5 menit timeout
        Cache::put(self::SYNC_CACHE_KEY . '.started_at', now()->toDateTimeString(), 300);
        Cache::put(self::SYNC_CACHE_KEY . '.progress', 'Memulai koneksi ke EMIS GTK...', 300);

        // Dispatch ke background queue
        RunEmisGtkSyncJob::dispatch();

        return response()->json([
            'success' => true,
            'message' => 'Sinkronisasi dimulai di background. Pantau statusnya di bawah.',
        ]);
    }

    /**
     * Polling status sync (SSE-like via JSON polling dari frontend).
     */
    public function syncStatus(): JsonResponse
    {
        return response()->json([
            'running'    => Cache::has(self::SYNC_CACHE_KEY . '.running'),
            'started_at' => Cache::get(self::SYNC_CACHE_KEY . '.started_at'),
            'progress'   => Cache::get(self::SYNC_CACHE_KEY . '.progress', '—'),
            'result'     => Cache::get(self::SYNC_CACHE_KEY . '.result'),
        ]);
    }
}
