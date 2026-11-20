<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Job diagnostik untuk membuktikan worker pada suatu queue berjalan.
 *
 * Contoh:
 *   QueueHealthCheckJob::dispatch('default')->onQueue('default');
 *   php artisan queue:work redis --queue=default --once
 *   Cache::get('queue-health:default');
 */
class QueueHealthCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $marker = 'default') {}

    public function handle(): void
    {
        Cache::put(
            "queue-health:{$this->marker}",
            now()->toIso8601String(),
            now()->addMinutes(10)
        );
    }
}
