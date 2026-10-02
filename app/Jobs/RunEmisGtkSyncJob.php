<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Services\EmisGtkScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunEmisGtkSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout     = 300; // 5 menit
    public int $tries       = 1;
    public int $backoff     = 30;

    public function handle(EmisGtkScraperService $scraper): void
    {
        $key = 'emis_gtk_sync_status';

        try {
            Cache::put("{$key}.progress", 'Menukar token SSO → sessionid...', 300);

            $result = $scraper->run();

            if ($result['success']) {
                Setting::set('emis_gtk_last_sync', now()->toDateTimeString());
            }

            Cache::put("{$key}.result", $result, 600);
            Cache::put("{$key}.progress", $result['message'], 300);

            Log::info('EMIS GTK Sync selesai', $result);
        } catch (\Throwable $e) {
            Cache::put("{$key}.result", [
                'success' => false,
                'message' => $e->getMessage(),
            ], 600);

            Log::error('EMIS GTK Sync gagal: ' . $e->getMessage());
        } finally {
            Cache::forget("{$key}.running");
        }
    }
}
