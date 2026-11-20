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

    public int $tries = 1;

    public int $timeout = 1200;

    public function handle(EmisService $emis): void
    {
        Log::info('[JOB] PullEmisStudentDataJob dimulai');

        $progress = function (array $p) {
            Cache::put('emis:pull:progress', $p, now()->addMinutes(30));
        };

        try {
            $progress(['status' => 'Mengambil data siswa dari EMIS...', 'progress' => 2]);

            $page = 1;
            $perPage = 100;
            $lastPage = null;
            $expectedTotal = null;
            $emptyStreak = 0;
            $stats = ['total_pulled' => 0, 'failed' => 0, 'skipped_no_nisn' => 0, 'pages' => 0];

            while (true) {
                // Metadata last_page EMIS tidak dipercaya (pernah 1 padahal data bersambung);
                // satu-satunya sinyal berhenti yang valid = halaman pendek/kosong.
                $response = $emis->fetchAllStudents($page, $perPage);
                $students = $response['results'] ?? [];
                $count = count($students);

                if ($expectedTotal === null && ! empty($response['total'])) {
                    $expectedTotal = (int) $response['total'];
                }
                if ($lastPage === null && ! empty($response['last_page'])) {
                    $lastPage = max(1, (int) $response['last_page']);
                }

                // Halaman kosong tapi masih ada ekspektasi → coba sekali lagi (token/rate-limit sesaat)
                if ($count === 0) {
                    $emptyStreak++;
                    Log::warning("[JOB] Halaman {$page} kosong (percobaan {$emptyStreak})", [
                        'last_page' => $lastPage, 'expected_total' => $expectedTotal,
                    ]);
                    if ($emptyStreak >= 2) {
                        break;
                    }
                    sleep(3);

                    continue;
                }
                $emptyStreak = 0;

                Log::info("[JOB] Halaman {$page}/".($lastPage ?? '?').": {$count} siswa ditarik");
                $stats['pages']++;

                foreach ($students as $emisStudent) {
                    try {
                        $parsed = $emis->parseEmisStudentRaw($emisStudent);

                        // NISN kosong tidak boleh jadi kunci updateOrCreate
                        // (WHERE nisn IS NULL akan menimpa baris yang sama berulang-ulang)
                        $nisn = $parsed['nisn'] ?: null;
                        if (empty($nisn)) {
                            $nisn = 'NONISN-'.($parsed['emis_student_id'] ?? $parsed['nik'] ?? uniqid());
                            $stats['skipped_no_nisn']++;
                        }

                        EmisStaging::updateOrCreate(
                            ['nisn' => $nisn],
                            [
                                'source' => 'emis_siswa',
                                'nik' => $parsed['nik'],
                                'name' => $parsed['full_name'],
                                'gender' => $parsed['gender'],
                                'rombel_name' => $parsed['rombel_name'],
                                'raw_data' => $emisStudent,
                                'status' => 'pending',
                            ]
                        );
                        $stats['total_pulled']++;
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        Log::error('[JOB] Gagal simpan staging', ['error' => $e->getMessage()]);
                    }
                }

                $pct = $lastPage && $lastPage > 1
                    ? min(98, (int) round($page / $lastPage * 100))
                    : min(98, 5 + $stats['pages'] * 5);
                $progress([
                    'status' => "Menarik halaman {$page}".($lastPage && $lastPage > 1 ? " dari {$lastPage}" : '').' ...',
                    'progress' => $pct,
                    'stats' => $stats + ['expected_total' => $expectedTotal],
                ]);

                // Berhenti hanya saat halaman pendek/kosong.
                // (Metadata last_page EMIS tidak bisa dipercaya: pernah berisi 1
                // padahal halaman berikut masih penuh.)
                if ($count < $perPage) {
                    break;
                }

                $page++;
                usleep(200_000);

                // Pengaman: jangan loop selamanya
                if ($page > 500) {
                    Log::error('[JOB] Batas 500 halaman tercapai, hentikan.');
                    break;
                }
            }

            $done = $stats + ['expected_total' => $expectedTotal];

            // Peringatan bila hasil jauh dari ekspektasi metadata
            if ($expectedTotal && $stats['total_pulled'] < $expectedTotal) {
                $msg = "Hanya {$stats['total_pulled']} dari {$expectedTotal} data EMIS yang tertarik. Ulangi penarikan (token mungkin kedaluwarsa di tengah jalan).";
                Log::warning('[JOB] Pull tidak lengkap', $done);
                $progress(['status' => $msg, 'progress' => 100, 'stats' => $done, 'incomplete' => true]);
            } else {
                $progress([
                    'status' => 'Selesai menarik data. Siap direview.',
                    'progress' => 100,
                    'stats' => $done,
                ]);
            }

            Log::info('[JOB] Pull selesai', $done);
        } catch (\Throwable $e) {
            Log::error('[JOB] Pull gagal', ['error' => $e->getMessage()]);
            $progress([
                'status' => 'ERROR: '.$e->getMessage(),
                'progress' => 100,
            ]);
            throw $e;
        }
    }
}
