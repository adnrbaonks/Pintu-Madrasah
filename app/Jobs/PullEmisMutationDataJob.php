<?php

namespace App\Jobs;

use App\Models\Student;
use App\Models\StudentMutation;
use App\Services\EmisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PullEmisMutationDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(EmisService $emis): void
    {
        Log::info('[JOB] PullEmisMutationDataJob dimulai');

        Cache::put('emis:mutations:progress', [
            'status' => 'Memulai sinkronisasi mutasi...',
            'progress' => 0,
            'stats' => null,
        ], now()->addMinutes(15));

        $stats = ['in' => 0, 'out' => 0, 'failed' => 0];

        $types = ['in', 'out'];
        foreach ($types as $type) {
            $page = 1;
            $perPage = 50; // keep it small to avoid timeout

            do {
                try {
                    $response = $emis->fetchMutations($type, $page, $perPage);
                    $results = $response['results'] ?? [];
                    $lastPage = (int) $response['last_page'];

                    foreach ($results as $item) {
                        $emisStudentId = $item['student_id'] ?? null;
                        if (! $emisStudentId) {
                            continue;
                        }

                        $student = $item['student'] ?? [];

                        // Tautkan ke siswa lokal bila NISN cocok — tetap draft (pending)
                        // sampai TU memverifikasi dokumen dan menyelesaikan manual.
                        $localId = null;
                        if (! empty($student['nisn'])) {
                            $localId = Student::where('nisn', $student['nisn'])->value('id');
                        }

                        StudentMutation::updateOrCreate(
                            [
                                'emis_student_id' => $emisStudentId,
                                'type' => $type,
                            ],
                            [
                                'student_id' => $localId,
                                'nisn' => $student['nisn'] ?? null,
                                'name' => $student['full_name'] ?? $item['name'] ?? null,
                                'mutation_date' => $item['mutation_date'] ?? $item['mutation_plan_date'] ?? null,
                                'reason' => $item['m_mutation_reason']['name'] ?? $item['m_mutation_reason_name'] ?? null,
                                'origin_school' => $item['institution_origin_name'] ?? null,
                                'destination_school' => $item['institution_destination_name'] ?? null,
                                'raw_data' => json_encode($item),
                            ]
                        );
                        $stats[$type]++;
                    }

                    Log::info("[JOB] Mutasi {$type} Halaman {$page} selesai ditarik.");
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    Log::error("[JOB] Gagal mutasi {$type} hal {$page}: ".$e->getMessage());
                    break; // stop this type on error
                }

                $page++;

                // Update progress loosely
                Cache::put('emis:mutations:progress', [
                    'status' => "Menarik mutasi {$type} halaman ".($page - 1),
                    'progress' => $type === 'in' ? 25 : 75,
                    'stats' => $stats,
                ], now()->addMinutes(15));

            } while ($page <= $lastPage);
        }

        Cache::put('emis:mutations:progress', [
            'status' => 'Selesai menarik data mutasi.',
            'progress' => 100,
            'stats' => $stats,
        ], now()->addMinutes(15));

        Log::info('[JOB] PullEmisMutationDataJob selesai', $stats);
    }
}
