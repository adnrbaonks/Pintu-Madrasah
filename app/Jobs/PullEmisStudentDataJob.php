<?php

namespace App\Jobs;

use App\Models\AcademicYear;
use App\Services\EmisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PullEmisStudentDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300; // 5 menit

    public function handle(EmisService $emis): void
    {
        Log::info('[JOB] PullEmisStudentDataJob dimulai');

        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            Log::warning('[JOB] Tidak ada tahun ajaran aktif. Sinkronisasi dibatalkan.');
            return;
        }

        try {
            // 1. Ambil daftar rombel
            $rombels = $emis->fetchRombelList();
            Log::info('[JOB] Daftar rombel diterima', ['total' => count($rombels)]);

            $stats = ['created' => 0, 'updated' => 0, 'protected_update' => 0, 'skipped' => 0, 'failed' => 0];

            // 2. Loop tiap rombel, tarik siswa
            foreach ($rombels as $rombel) {
                $rombelId = $rombel['id'] ?? $rombel['rombel_id'] ?? null;
                if (! $rombelId) continue;

                $students = $emis->fetchStudentsByRombel($rombelId);
                Log::info("[JOB] Rombel {$rombel['name']} — {$rombelId}: " . count($students) . ' siswa');

                foreach ($students as $emisStudent) {
                    try {
                        $result = $emis->upsertStudent($emisStudent, $activeYear);
                        $stats[$result['status']] = ($stats[$result['status']] ?? 0) + 1;
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        Log::error('[JOB] Gagal upsert siswa', [
                            'error' => $e->getMessage(),
                            'nisn'  => $emisStudent['nisn'] ?? 'unknown',
                        ]);
                    }
                }

                // Jeda singkat agar tidak membebani server EMIS
                usleep(200_000); // 0.2 detik
            }

            Log::info('[JOB] Sinkronisasi EMIS selesai', $stats);

        } catch (\Throwable $e) {
            Log::error('[JOB] PullEmisStudentDataJob gagal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Retry otomatis oleh queue
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('[JOB] PullEmisStudentDataJob gagal semua percobaan', [
            'error' => $e->getMessage(),
        ]);
    }
}
