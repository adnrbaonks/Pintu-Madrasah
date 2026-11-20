<?php

namespace App\Console\Commands;

use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--keep=7 : Jumlah file backup lokal yang dipertahankan}';

    protected $description = 'Dump database harian (00.00 WIB via scheduler) + upload ke Google Drive bila terkonfigurasi.';

    public function handle(GoogleDriveService $drive): int
    {
        $disk = Storage::disk('local');
        $dir = 'backups';
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $stamp = now()->format('Ymd-His');
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            $source = config("database.connections.{$connection}.database");
            $target = "{$dir}/backup-{$stamp}.sqlite";
            $disk->put($target, file_get_contents($source));
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $target = "{$dir}/backup-{$stamp}.sql";
            $this->dumpMysql($disk->path($target));
        } else {
            $this->error("Driver {$driver} belum didukung backup otomatis.");

            return self::FAILURE;
        }

        $this->info("Backup tersimpan: {$target} (".number_format($disk->size($target) / 1024, 1).' KB)');

        // Retensi lokal
        $files = collect($disk->files($dir))->sort()->values();
        $keep = (int) $this->option('keep');
        if ($files->count() > $keep) {
            foreach ($files->take($files->count() - $keep) as $old) {
                $disk->delete($old);
                $this->line("Hapus backup lama: {$old}");
            }
        }

        // Upload ke Drive (best-effort)
        try {
            $abs = $disk->path($target);
            $result = $drive->uploadFile($abs, mime_content_type($abs) ?: 'application/octet-stream', 'BACKUP_'.basename($target));
            if ($result) {
                $this->info('Backup terupload ke Google Drive: '.$result['gdrive_file_id']);
            } else {
                $this->warn('Upload Drive dilewati (folder/key belum dikonfigurasi). File lokal tetap aman.');
            }
        } catch (\Throwable $e) {
            Log::warning('[BACKUP] Upload Drive gagal', ['error' => $e->getMessage()]);
            $this->warn('Upload Drive gagal, file lokal tetap aman: '.$e->getMessage());
        }

        Log::info('[BACKUP] Selesai', ['file' => $target]);

        return self::SUCCESS;
    }

    private function dumpMysql(string $targetPath): void
    {
        $c = config('database.connections.'.config('database.default'));
        $cmd = sprintf(
            'mysqldump --single-transaction --quick -h %s -P %s -u %s %s %s > %s 2>&1',
            escapeshellarg($c['host'] ?? '127.0.0.1'),
            escapeshellarg($c['port'] ?? '3306'),
            escapeshellarg($c['username']),
            $c['password'] ? '-p'.escapeshellarg($c['password']) : '',
            escapeshellarg($c['database']),
            escapeshellarg($targetPath)
        );
        exec($cmd, $out, $code);
        if ($code !== 0 || ! filesize($targetPath)) {
            throw new \RuntimeException('mysqldump gagal: '.implode("\n", $out));
        }
    }
}
