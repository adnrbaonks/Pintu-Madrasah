<?php

namespace App\Jobs;

use App\Models\AcademicYear;
use App\Models\EmisStaging;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentAcademicCohort;
use App\Services\EmisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommitEmisStagingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;

    public function handle(EmisService $emis): void
    {
        Log::info('[JOB] CommitEmisStagingJob dimulai');

        $activeYear = AcademicYear::active();
        if (! $activeYear) {
            Log::error('[JOB] Tahun Ajaran Aktif belum diatur');
            return;
        }

        Cache::forget('emis:commit:pending');
        Cache::put('emis:commit:progress', [
            'status' => 'Memproses data siswa...',
            'progress' => 10,
        ], now()->addMinutes(15));

        $stagings = EmisStaging::where('status', 'pending')->get();
        $total = $stagings->count();

        if ($total === 0) {
            Cache::put('emis:commit:progress', [
                'status' => 'Tidak ada data untuk diproses',
                'progress' => 100,
            ], now()->addMinutes(15));
            return;
        }

        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'rombel_created' => 0, 'failures' => []];
        $processedNisn = [];

        foreach ($stagings as $staging) {
            try {
                $parsed = [
                    'emis_student_id' => $staging->raw_data['id'] ?? null,
                    'nisn' => $staging->nisn,
                    'nik' => $staging->nik,
                    'name' => $staging->name,
                    'gender' => $staging->gender ?: 'L', // Default jika null
                    'birth_date' => $staging->raw_data['birth_date'] ?? null,
                    'birth_place' => $staging->raw_data['birth_place'] ?? null,
                    'status' => 'active',
                ];

                $student = Student::withTrashed()->where('nisn', $staging->nisn)->first();

                if ($student) {
                    // Smart Fill (Pendekatan 2): Lindungi Data Lokal
                    // Hanya update kolom yang di lokal masih benar-benar kosong (null atau string kosong)
                    $smartUpdate = [];
                    foreach ($parsed as $field => $newValue) {
                        $oldValue = $student->$field;
                        if (empty(trim((string)$oldValue)) || $oldValue === '-') {
                            $smartUpdate[$field] = $newValue;
                        }
                    }

                    if (!empty($smartUpdate)) {
                        $student->update($smartUpdate);
                        $stats['updated']++;
                    }
                } else {
                    $student = Student::create($parsed);
                    $stats['created']++;
                }

                $processedNisn[] = $staging->nisn;

                // Simpan grade_level dari rombel_name meski rombel tidak valid
                $gradeLevel = $emis->parseGradeLevel($staging->rombel_name);
                if ($gradeLevel && empty($student->grade_level)) {
                    $student->update(['grade_level' => $gradeLevel]);
                }

                // Auto-assign ke rombel
                if ($staging->rombel_name && $staging->rombel_name !== 'Belum Masuk Rombel') {
                    // Validasi nama rombel: harus mengandung 1 grade level yang valid, bukan kombinasi seperti "7/8"
                    if (self::isValidRombelName($staging->rombel_name)) {
                        $gradeLevel = $emis->parseGradeLevel($staging->rombel_name);
                        
                        if ($gradeLevel) {
                            $schoolClass = SchoolClass::firstOrCreate(['grade_level' => $gradeLevel]);

                            $rombel = Rombel::firstOrCreate(
                                [
                                    'academic_year_id' => $activeYear->id,
                                    'class_id' => $schoolClass->id,
                                    'name' => $staging->rombel_name,
                                ]
                            );

                            if ($rombel->wasRecentlyCreated) {
                                $stats['rombel_created']++;
                            }

                            StudentAcademicCohort::updateOrCreate(
                                [
                                    'student_id' => $student->id,
                                    'academic_year_id' => $activeYear->id,
                                ],
                                [
                                    'rombel_id' => $rombel->id,
                                    'semester' => $activeYear->semester,
                                    'status' => 'active',
                                ]
                            );
                        }
                    } else {
                        Log::warning("[JOB] Rombel tidak valid, siswa tidak di-assign: {$staging->rombel_name}", ['nisn' => $staging->nisn]);
                    }
                }

                $staging->update(['status' => 'committed']);

            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error("[JOB] Gagal commit NISN: {$staging->nisn}", ['error' => $e->getMessage()]);
                if (count($stats['failures']) < 50) {
                    $stats['failures'][] = [
                        'nisn' => $staging->nisn,
                        'name' => $staging->name,
                        'reason' => self::humanReason($e),
                    ];
                }
            }
        }

        // Cleanup staging
        EmisStaging::where('status', 'committed')->delete();

        Cache::put('emis:commit:progress', [
            'status' => 'Sinkronisasi selesai!',
            'progress' => 100,
            'stats' => $stats,
        ], now()->addMinutes(15));

        Log::info('[JOB] Commit selesai', $stats);
    }

    /**
     * Terjemahkan error teknis menjadi bahasa operator TU.
     */
    private static function humanReason(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (preg_match('/NOT NULL constraint failed: (\w+)\.(\w+)/', $msg, $m)) {
            $field = $m[2];
            $label = [
                'birth_place' => 'tempat lahir', 'birth_date' => 'tanggal lahir',
                'name' => 'nama', 'nisn' => 'NISN', 'nik' => 'NIK',
                'gender' => 'jenis kelamin', 'status' => 'status',
            ][$field] ?? $field;
            return "Data {$label} kosong dari EMIS dan wajib diisi — lengkapi manual di EMIS/baris ini.";
        }
        if (str_contains($msg, 'UNIQUE constraint failed') || str_contains($msg, 'Duplicate entry')) {
            return 'Data ganda (NISN/NIK sudah dipakai siswa lain) — cek duplikat di Master Siswa.';
        }
        if (str_contains($msg, 'FOREIGN KEY') || str_contains($msg, 'foreign key')) {
            return 'Relasi data (rombel/tahun ajaran) tidak ditemukan — pastikan rombel & tahun aktif benar.';
        }

        return 'Kesalahan sistem: ' . mb_substr($msg, 0, 160);
    }

    /**
     * Validasi nama rombel dari EMIS.
     * Rombel valid harus mengandung tepat 1 grade level (VII-XII atau 7-12)
     * dan tidak boleh kombinasi seperti "7/8", "VII/VIII", "Kelas 7 & 8".
     */
    private static function isValidRombelName(string $name): bool
    {
        $normalized = mb_strtoupper(trim($name));

        // Pattern: harus mengandung tepat satu grade level yang berdiri sendiri
        // Valid: "VII A", "Kelas 7", "VII-A", "Kelas VIII B", "7A"
        // Invalid: "7/8", "VII/VIII", "KELAS 7 & 8", "7/8A"
        
        // Hitung jumlah grade level yang ditemukan
        preg_match_all('/\b(VII|VIII|IX|X|XI|XII)\b|(?<!\d)(7|8|9|10|11|12)(?!\d)/i', $normalized, $matches);
        $gradeCount = count($matches[0]);

        // Harus tepat 1 grade level
        if ($gradeCount !== 1) {
            return false;
        }

        // Cek karakter penghubung yang mencurigakan (/, &, +, koma)
        if (preg_match('/[\/&+,]/', $normalized)) {
            return false;
        }

        return true;
    }
}
