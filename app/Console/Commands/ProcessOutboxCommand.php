<?php

namespace App\Console\Commands;

use App\Services\OutboxService;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'webhooks:process-outbox
                            {--limit=100 : Jumlah maksimum outbox event yang diproses}
                            {--retry-failed : Ikut memproses ulang outbox event yang berstatus failed (dead-letter)}';

    protected $description = 'Proses antrean transactional outbox_events dan jadwalkan pengiriman webhook ke pelanggan aktif.';

    public function handle(OutboxService $outboxService): int
    {
        $limit = max((int) $this->option('limit'), 1);
        $retryFailed = (bool) $this->option('retry-failed');

        $count = $outboxService->processPending($limit, $retryFailed);

        $this->info("Berhasil memproses {$count} outbox event.");

        return self::SUCCESS;
    }
}
