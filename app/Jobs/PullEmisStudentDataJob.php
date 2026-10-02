<?php

namespace App\Jobs;

use App\Models\EmisStaging;
use App\Services\EmisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PullEmisStudentDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function handle(EmisService $emis): void
    {
        Log::info('[JOB] PullEmisStudentDataJob dimulai');

        try {
            Cache::put('emis:pull:progress', [
                'status' => 'Mengambil data siswa dari EMIS...',
                'progress' => 5,
            ], now()->addMinutes(15));

            $page = 1;
            $perPage = 100;
            $stats = ['total_pulled' => 0, 'failed' => 0];

            while (true) {
                $response = $emis->fetchAllStudents($page, $perPage);
                $students = $response['results'];
                $count = count($students);

                Log::info("[JOB] Halaman {$page}: {$count} siswa ditarik");

                if ($count === 0) break;

                foreach ($students as $emisStudent) {
                    try {
                        $parsed = $emis->parseEmisStudentRaw($emisStudent);

                        EmisStaging::updateOrCreate(
                            ['nisn' => $parsed['nisn']],
                            [
                                'nik'       => $parsed['nik'],
                                'name'      => $parsed['full_name'],
                                'gender'    => $parsed['gender'],
                                'rombel_name' => $parsed['rombel_name'],
                                'raw_data'  => $emisStudent,
                                'status'    => 'pending',
                            ]
                        );
                        $stats['total_pulled']++;
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        Log::error('[JOB] Gagal simpan staging', ['error' => $e->getMessage()]);
                    }
                }

                if ($count < $perPage) break;
                $page++;
                usleep(200_000);
            }

            Cache::put('emis:pull:progress', [
                'status' => 'Selesai menarik data. Siap direview.',
                'progress' => 100,
                'stats' => $stats,
            ], now()->addMinutes(15));

            Log::info('[JOB] Pull selesai', $stats);

        } catch (\Throwable $e) {
            Log::error('[JOB] Pull gagal', ['error' => $e->getMessage()]);
            Cache::put('emis:pull:progress', [
                'status' => 'ERROR: ' . $e->getMessage(),
                'progress' => 100,
            ], now()->addMinutes(15));
            throw $e;
        }
    }
}
