<?php

namespace App\Jobs;

use App\Models\EmisGtkStaging;
use App\Services\EmisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PullEmisGtkDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function handle(EmisService $emisService): void
    {
        Log::info('[EMIS GTK] Memulai penarikan data GTK dari EMIS...');

        Cache::put('emis_gtk:pull:progress', ['progress' => 0, 'status' => 'Mulai menarik data...'], now()->addHours(2));

        try {
            $page = 1;
            $perPage = 50;
            $totalFetched = 0;

            do {
                $response = $emisService->fetchGtkList($page, $perPage);
                $results = $response['results'];
                $lastPage = $response['last_page'];
                $total = $response['total'];

                foreach ($results as $gtkData) {
                    $parsed = $emisService->parseEmisGtkRaw($gtkData);
                    
                    EmisGtkStaging::updateOrCreate(
                        ['emis_gtk_id' => $parsed['emis_gtk_id']],
                        $parsed
                    );
                    $totalFetched++;
                }

                $progress = $total > 0 ? min(100, round(($totalFetched / $total) * 100)) : 100;
                Cache::put('emis_gtk:pull:progress', [
                    'progress' => $progress,
                    'status' => "Menarik data halaman $page dari $lastPage...",
                ], now()->addHours(2));

                $page++;
            } while ($page <= $lastPage);

            Cache::put('emis_gtk:pull:progress', [
                'progress' => 100,
                'status' => "Selesai! $totalFetched data GTK berhasil ditarik ke staging.",
            ], now()->addHours(2));

            Log::info("[EMIS GTK] Penarikan selesai. Total: $totalFetched");

        } catch (\Exception $e) {
            Log::error('[EMIS GTK] Error PullEmisGtkDataJob: ' . $e->getMessage());
            Cache::put('emis_gtk:pull:progress', [
                'progress' => 100,
                'status' => 'Gagal: ' . $e->getMessage(),
                'error' => true,
            ], now()->addHours(2));
        }
    }
}
