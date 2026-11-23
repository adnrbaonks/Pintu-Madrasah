<?php

namespace App\Console\Commands;

use App\Services\OutboxService;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'webhooks:process-outbox
                            {--limit=100 : Jumlah maksimum outbox event yang diproses}
                            {--lease=300 : Durasi lease lock dalam detik}
                            {--retry-failed : Ikut memproses ulang outbox event yang berstatus failed (dead-letter)}';

    protected $description = 'Proses antrean transactional outbox_events dan jadwalkan pengiriman webhook ke pelanggan aktif dengan atomic claim dan lease recovery.';

    public function handle(OutboxService $outboxService): int
    {
        $limit = max((int) $this->option('limit'), 1);
        $lease = max((int) $this->option('lease'), 10);
        $retryFailed = (bool) $this->option('retry-failed');

        $recovered = $outboxService->recoverStalled();
        if ($recovered > 0) {
            $this->warn("Dipulihkan {$recovered} outbox event yang terhenti (stalled).");
        }

        $count = $outboxService->processPending(
            limit: $limit,
            retryFailed: $retryFailed,
            leaseSeconds: $lease,
        );

        $this->info("Berhasil memproses {$count} outbox event.");

        return self::SUCCESS;
    }
}
