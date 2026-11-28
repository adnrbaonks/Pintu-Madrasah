<?php

namespace App\Console\Commands;

use App\Services\EmisImportSessionService;
use Illuminate\Console\Command;

class CleanupEmisImportSessionsCommand extends Command
{
    protected $signature = 'emis:import-sessions:cleanup {--batch= : Ukuran batch maksimum per iterasi}';

    protected $description = 'Bersihkan sesi import EMIS yang kedaluwarsa, payload gagal di luar masa retensi, dan metadata terminal lama tanpa PII.';

    public function handle(EmisImportSessionService $service): int
    {
        $batchOption = $this->option('batch');
        $batchSize = $batchOption !== null && $batchOption !== '' ? max(1, (int) $batchOption) : null;

        $stats = $service->cleanupSessions($batchSize);

        $this->info(sprintf(
            'Cleanup sesi import EMIS selesai — expired_open=%d, recovered_stale_finalizing=%d, purged_failed_payloads=%d, pruned_terminal_metadata=%d, deleted_pages=%d',
            $stats['expired_open_sessions'],
            $stats['recovered_stale_finalizing_sessions'],
            $stats['purged_failed_payload_sessions'],
            $stats['pruned_terminal_metadata_sessions'],
            $stats['deleted_page_rows'],
        ));

        return self::SUCCESS;
    }
}
