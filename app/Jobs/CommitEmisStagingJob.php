<?php

namespace App\Jobs;

use App\Models\AcademicYear;
use App\Models\EmisImportBatch;
use App\Models\EmisReconciliationItem;
use App\Models\EmisStaging;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentAcademicCohort;
use App\Services\EmisReconciliationService;
use App\Services\EmisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CommitEmisStagingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public ?string $actorId = null,
        public bool $allowOverwrite = false,
        public bool $overwriteConfirmed = false,
        public ?string $batchId = null,
    ) {}

    public function handle(?EmisService $emis = null): void
    {
        Log::info('[JOB] CommitEmisStagingJob dimulai');

        $emis ??= app(EmisService::class);
        $reconciliationService = app(EmisReconciliationService::class);
        $canOverwrite = $this->allowOverwrite && $this->overwriteConfirmed;

        $batch = $this->batchId
            ? EmisImportBatch::find($this->batchId)
            : null;

        $batch ??= $reconciliationService->createBatch(
            entityType: 'student',
            sourceSystem: 'emis_student',
            processType: EmisImportBatch::PROCESS_INITIAL_IMPORT,
            actorId: $this->actorId,
            allowOverwrite: $this->allowOverwrite,
            overwriteConfirmed: $this->overwriteConfirmed,
            notes: $canOverwrite
                ? 'Import ulang EMIS Siswa dengan konfirmasi overwrite eksplisit.'
                : 'Import awal EMIS Siswa (non-destructive terhadap master PINTU).',
        );

        $activeYear = AcademicYear::active();

        Cache::forget('emis:commit:pending');
        Cache::put('emis:commit:progress', [
            'status' => 'Memproses data siswa...',
            'progress' => 10,
        ], now()->addMinutes(15));

        $stagings = EmisStaging::where('status', 'pending')->get();
        $total = $stagings->count();

        if ($total === 0) {
            $batch->update(['total_rows' => 0]);
            Cache::put('emis:commit:progress', [
                'status' => 'Tidak ada data untuk diproses',
                'progress' => 100,
            ], now()->addMinutes(15));

            return;
        }

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped_existing' => 0,
            'matched_identical' => 0,
            'differences' => 0,
            'needs_review' => 0,
            'failed' => 0,
            'rombel_created' => 0,
            'failures' => [],
            'batch_id' => $batch->id,
        ];
        $processedNisn = [];

        foreach ($stagings as $staging) {
            try {
                $emisStudentId = isset($staging->raw_data['id']) && filled($staging->raw_data['id'])
                    ? (string) $staging->raw_data['id']
                    : null;

                $parsed = [
                    'emis_student_id' => $emisStudentId,
                    'nisn' => $staging->nisn,
                    'nik' => $staging->nik,
                    'name' => $staging->name,
                    'gender' => $staging->gender ?: 'L', // Default jika null
                    'birth_date' => $staging->birth_date ?? ($staging->raw_data['birth_date'] ?? null),
                    'birth_place' => $staging->birth_place ?? ($staging->raw_data['birth_place'] ?? null),
                    'status' => 'active',
                ];

                $student = $this->findMatchingStudent($emisStudentId, $staging->nisn, $staging->nik, $hasConflict);

                if ($hasConflict) {
                    $stats['needs_review']++;
                    EmisReconciliationItem::create([
                        'batch_id' => $batch->id,
                        'entity_type' => 'student',
                        'entity_id' => $student?->id,
                        'staging_id' => $staging->id,
                        'external_reference_id' => $emisStudentId ?: $staging->nisn,
                        'classification' => EmisReconciliationItem::CLASS_NEEDS_REVIEW,
                        'review_notes' => 'Konflik identitas antara ID EMIS, NISN, dan NIK pada master PINTU.',
                    ]);

                    continue;
                }

                if ($student) {
                    $differences = $reconciliationService->compareStudentWithStaging($student, $staging);

                    if ($differences === []) {
                        $stats['matched_identical']++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'student',
                            'entity_id' => $student->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $student->emis_student_id ?: $student->nisn,
                            'classification' => EmisReconciliationItem::CLASS_SAME,
                            'differences' => [],
                        ]);
                    } else {
                        $stats['differences']++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'student',
                            'entity_id' => $student->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $student->emis_student_id ?: $student->nisn,
                            'classification' => EmisReconciliationItem::CLASS_DIFFERENT,
                            'differences' => $differences,
                            'review_notes' => $canOverwrite
                                ? 'Import ulang dengan konfirmasi overwrite.'
                                : 'Master PINTU dipertahankan sebagai SSOT; perbedaan dicatat sebagai temuan.',
                        ]);
                    }

                    // Kebijakan SSOT PINTU: Jangan timpa data master PINTU yang sudah terisi
                    // kecuali import ulang dikonfirmasi secara eksplisit dan tidak terkunci.
                    $smartUpdate = [];
                    foreach ($parsed as $field => $newValue) {
                        if ($newValue === null || $newValue === '') {
                            continue;
                        }

                        $oldValue = $student->$field;
                        $isEmptyLocal = empty(trim((string) $oldValue)) || $oldValue === '-';

                        if ($isEmptyLocal) {
                            $smartUpdate[$field] = $newValue;
                        } elseif ($canOverwrite && ! $student->isIdentityFieldProtected()) {
                            $smartUpdate[$field] = $newValue;
                        }
                    }

                    if (! empty($smartUpdate)) {
                        $student->update($smartUpdate);
                        $stats['updated']++;
                    } elseif ($differences !== []) {
                        $stats['skipped_existing']++;
                    }
                } else {
                    $student = Student::create($parsed);
                    $stats['created']++;
                }

                $processedNisn[] = $staging->nisn;

                // Simpan grade_level dari rombel_name meski rombel tidak valid
                $gradeLevel = $emis->parseGradeLevel((string) $staging->rombel_name);
                if ($gradeLevel && empty($student->grade_level)) {
                    $student->update(['grade_level' => $gradeLevel]);
                }

                // Auto-assign ke rombel jika tahun ajaran aktif tersedia
                if ($activeYear && $staging->rombel_name && $staging->rombel_name !== 'Belum Masuk Rombel') {
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

        $batch->update([
            'total_rows' => $total,
            'created_count' => $stats['created'],
            'updated_count' => $stats['updated'],
            'skipped_existing_count' => $stats['skipped_existing'],
            'matched_identical_count' => $stats['matched_identical'],
            'difference_count' => $stats['differences'],
            'needs_review_count' => $stats['needs_review'] + $stats['failed'],
        ]);

        Cache::put('emis:commit:progress', [
            'status' => 'Sinkronisasi selesai!',
            'progress' => 100,
            'stats' => $stats,
        ], now()->addMinutes(15));

        Log::info('[JOB] Commit selesai', $stats);
    }

    private function findMatchingStudent(?string $emisStudentId, ?string $nisn, ?string $nik, ?bool &$hasConflict = false): ?Student
    {
        $hasConflict = false;

        $byEmisId = filled($emisStudentId)
            ? Student::withTrashed()->where('emis_student_id', $emisStudentId)->first()
            : null;
        $byNisn = filled($nisn)
            ? Student::withTrashed()->where('nisn', $nisn)->first()
            : null;
        $byNik = filled($nik)
            ? Student::withTrashed()->where('nik', $nik)->first()
            : null;

        $candidates = collect([$byEmisId, $byNisn, $byNik])->filter();
        $uniqueIds = $candidates->map(fn (Student $s): string => $s->id)->unique()->values();

        if ($uniqueIds->count() > 1) {
            $hasConflict = true;

            return $candidates->first();
        }

        return $candidates->first();
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

        return 'Kesalahan sistem: '.mb_substr($msg, 0, 160);
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
