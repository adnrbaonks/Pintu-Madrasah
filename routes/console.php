<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup DB harian 00.00 WIB (cron server: * * * * * php artisan schedule:run)
Schedule::command('backup:run --keep=7')->dailyAt('00:00')->timezone('Asia/Jakarta');

// Pemrosesan transactional outbox & pemulihan lease berkala setiap menit tanpa tumpang tindih
Schedule::command('webhooks:process-outbox')->everyMinute()->withoutOverlapping();
