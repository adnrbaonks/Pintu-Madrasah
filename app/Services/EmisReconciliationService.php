<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EmisGtkStaging;
use App\Models\EmisImportBatch;
use App\Models\EmisReconciliationItem;
use App\Models\EmisStaging;
use App\Models\EmisUpdateTask;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EmisReconciliationService
{
    /**
     * Daftar eksplisit atribut rekonsiliasi Siswa antara PINTU (SSOT) dan EMIS.
     *
     * @var array<int, string>
     */
    public const STUDENT_RECONCILIATION_FIELDS = [
        'nisn',
        'nik',
        'kk_number',
        'name',
        'gender',
        'birth_place',
        'birth_date',
        'mother_name',
        'father_name',
        'address',
    ];

    /**
     * Daftar eksplisit atribut rekonsiliasi GTK antara PINTU (SSOT) dan EMIS GTK.
     *
     * @var array<int, string>
     */
    public const STAFF_RECONCILIATION_FIELDS = [
        'nik',
        'nip',
        'nuptk',
        'npk',
        'full_name_with_titles',
        'first_title',
        'last_title',
        'gender',
        'birth_place',
        'birth_date',
        'phone',
        'email',
        'employment_status',
        'civil_rank',
        'civil_rank_tmt',
        'functional_position',
        'highest_education',
        'university_name',
        'study_program',
        'graduation_year',
    ];

    public function __construct(
        private DataOwnershipService $ownershipService,
    ) {}

    /**
     * Buat header batch untuk proses Import Awal maupun Rekonsiliasi EMIS.
     */
    public function createBatch(
        string $entityType,
        string $sourceSystem,
        string $processType,
        ?string $actorId = null,
        bool $allowOverwrite = false,
        bool $overwriteConfirmed = false,
        ?string $notes = null,
    ): EmisImportBatch {
        $prefix = $processType === EmisImportBatch::PROCESS_RECONCILIATION ? 'EMIS-REC' : 'EMIS-IMP';

        return EmisImportBatch::create([
            'entity_type' => $entityType,
            'source_system' => $sourceSystem,
            'process_type' => $processType,
            'process_identifier' => sprintf('%s-%s-%s', $prefix, now()->format('YmdHis'), Str::upper(Str::random(6))),
            'allow_overwrite' => $allowOverwrite,
            'overwrite_confirmed' => $overwriteConfirmed,
            'imported_by' => $actorId,
            'imported_at' => now(),
            'notes' => $notes,
        ]);
    }

    /**
     * Rekonsiliasi non-destruktif data Siswa antara master PINTU (SSOT) dan staging EMIS.
     * Tidak pernah mengubah data master Student di PINTU.
     */
    public function reconcileStudents(?string $actorId = null, ?string $notes = null): EmisImportBatch
    {
        return DB::transaction(function () use ($actorId, $notes): EmisImportBatch {
            $batch = $this->createBatch(
                entityType: 'student',
                sourceSystem: 'emis_student',
                processType: EmisImportBatch::PROCESS_RECONCILIATION,
                actorId: $actorId,
                notes: $notes ?? 'Rekonsiliasi produksi PINTU (SSOT) vs EMIS Siswa',
            );

            $students = Student::query()->get();
            $studentIndex = $this->buildStudentLookupIndex($students);

            $matchedStudentIds = [];
            $sameCount = 0;
            $diffCount = 0;
            $emisOnlyCount = 0;
            $needsReviewCount = 0;
            $totalRows = 0;

            EmisStaging::query()->orderBy('id')->chunk(500, function (Collection $stagings) use (
                $batch,
                $studentIndex,
                &$matchedStudentIds,
                &$sameCount,
                &$diffCount,
                &$emisOnlyCount,
                &$needsReviewCount,
                &$totalRows,
            ): void {
                foreach ($stagings as $staging) {
                    $totalRows++;
                    $resolution = $this->resolveStudentMatch($staging, $studentIndex);

                    if ($resolution['status'] === 'needs_review') {
                        $needsReviewCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'student',
                            'entity_id' => $resolution['student']?->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $this->extractStudentStagingValue($staging, 'emis_student_id') ?: $staging->nisn,
                            'classification' => EmisReconciliationItem::CLASS_NEEDS_REVIEW,
                            'differences' => $resolution['differences'] ?? null,
                            'pintu_snapshot' => $resolution['student'] ? $this->studentSnapshot($resolution['student']) : null,
                            'emis_snapshot' => $this->studentStagingSnapshot($staging),
                            'review_notes' => $resolution['reason'],
                        ]);

                        if ($resolution['student']) {
                            $matchedStudentIds[$resolution['student']->id] = true;
                        }

                        continue;
                    }

                    /** @var Student|null $student */
                    $student = $resolution['student'];

                    if (! $student) {
                        $emisOnlyCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'student',
                            'entity_id' => null,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $this->extractStudentStagingValue($staging, 'emis_student_id') ?: $staging->nisn,
                            'classification' => EmisReconciliationItem::CLASS_EMIS_ONLY,
                            'differences' => null,
                            'pintu_snapshot' => null,
                            'emis_snapshot' => $this->studentStagingSnapshot($staging),
                            'review_notes' => 'Data ditemukan di staging EMIS namun belum terdaftar di master PINTU.',
                        ]);

                        continue;
                    }

                    $matchedStudentIds[$student->id] = true;
                    $differences = $this->compareStudentWithStaging($student, $staging);

                    if ($differences === []) {
                        $sameCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'student',
                            'entity_id' => $student->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $student->emis_student_id ?: $student->nisn,
                            'classification' => EmisReconciliationItem::CLASS_SAME,
                            'differences' => [],
                            'pintu_snapshot' => $this->studentSnapshot($student),
                            'emis_snapshot' => $this->studentStagingSnapshot($staging),
                        ]);
                    } else {
                        $diffCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'student',
                            'entity_id' => $student->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $student->emis_student_id ?: $student->nisn,
                            'classification' => EmisReconciliationItem::CLASS_DIFFERENT,
                            'differences' => $differences,
                            'pintu_snapshot' => $this->studentSnapshot($student),
                            'emis_snapshot' => $this->studentStagingSnapshot($staging),
                            'review_notes' => 'Ditemukan perbedaan atribut antara master PINTU dan EMIS. Master PINTU dipertahankan sebagai SSOT.',
                        ]);
                    }
                }
            });

            $pintuOnlyCount = 0;
            foreach ($students as $student) {
                if (isset($matchedStudentIds[$student->id])) {
                    continue;
                }

                $pintuOnlyCount++;
                EmisReconciliationItem::create([
                    'batch_id' => $batch->id,
                    'entity_type' => 'student',
                    'entity_id' => $student->id,
                    'staging_id' => null,
                    'external_reference_id' => $student->emis_student_id ?: $student->nisn,
                    'classification' => EmisReconciliationItem::CLASS_PINTU_ONLY,
                    'differences' => null,
                    'pintu_snapshot' => $this->studentSnapshot($student),
                    'emis_snapshot' => null,
                    'review_notes' => 'Data ada di master PINTU namun tidak ditemukan pada dataset pembanding EMIS.',
                ]);
            }

            $batch->update([
                'total_rows' => $totalRows,
                'matched_identical_count' => $sameCount,
                'difference_count' => $diffCount,
                'pintu_only_count' => $pintuOnlyCount,
                'emis_only_count' => $emisOnlyCount,
                'needs_review_count' => $needsReviewCount,
            ]);

            return $batch->fresh(['items']);
        });
    }

    /**
     * Rekonsiliasi non-destruktif data GTK antara master PINTU (SSOT) dan staging EMIS GTK.
     * Tidak pernah mengubah data master Staff di PINTU.
     */
    public function reconcileStaff(?string $actorId = null, ?string $notes = null): EmisImportBatch
    {
        return DB::transaction(function () use ($actorId, $notes): EmisImportBatch {
            $batch = $this->createBatch(
                entityType: 'staff',
                sourceSystem: 'emis_gtk',
                processType: EmisImportBatch::PROCESS_RECONCILIATION,
                actorId: $actorId,
                notes: $notes ?? 'Rekonsiliasi produksi PINTU (SSOT) vs EMIS GTK',
            );

            $staffMembers = Staff::query()->get();
            $staffIndex = $this->buildStaffLookupIndex($staffMembers);

            $matchedStaffIds = [];
            $sameCount = 0;
            $diffCount = 0;
            $emisOnlyCount = 0;
            $needsReviewCount = 0;
            $totalRows = 0;

            EmisGtkStaging::query()->orderBy('id')->chunk(500, function (Collection $stagings) use (
                $batch,
                $staffIndex,
                &$matchedStaffIds,
                &$sameCount,
                &$diffCount,
                &$emisOnlyCount,
                &$needsReviewCount,
                &$totalRows,
            ): void {
                foreach ($stagings as $staging) {
                    $totalRows++;
                    $resolution = $this->resolveStaffMatch($staging, $staffIndex);

                    if ($resolution['status'] === 'needs_review') {
                        $needsReviewCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'staff',
                            'entity_id' => $resolution['staff']?->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $staging->emis_gtk_id ?: ($staging->nik ?: ($staging->nuptk ?: ($staging->nip ?: $staging->npk))),
                            'classification' => EmisReconciliationItem::CLASS_NEEDS_REVIEW,
                            'differences' => $resolution['differences'] ?? null,
                            'pintu_snapshot' => $resolution['staff'] ? $this->staffSnapshot($resolution['staff']) : null,
                            'emis_snapshot' => $this->staffStagingSnapshot($staging),
                            'review_notes' => $resolution['reason'],
                        ]);

                        if ($resolution['staff']) {
                            $matchedStaffIds[$resolution['staff']->id] = true;
                        }

                        continue;
                    }

                    /** @var Staff|null $staff */
                    $staff = $resolution['staff'];

                    if (! $staff) {
                        $emisOnlyCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'staff',
                            'entity_id' => null,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $staging->emis_gtk_id ?: ($staging->nik ?: ($staging->nuptk ?: ($staging->nip ?: $staging->npk))),
                            'classification' => EmisReconciliationItem::CLASS_EMIS_ONLY,
                            'differences' => null,
                            'pintu_snapshot' => null,
                            'emis_snapshot' => $this->staffStagingSnapshot($staging),
                            'review_notes' => 'Data ditemukan di staging EMIS GTK namun belum terdaftar di master PINTU.',
                        ]);

                        continue;
                    }

                    $matchedStaffIds[$staff->id] = true;
                    $differences = $this->compareStaffWithStaging($staff, $staging);

                    if ($differences === []) {
                        $sameCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'staff',
                            'entity_id' => $staff->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $staff->emis_gtk_id ?: ($staff->nik ?: ($staff->nuptk ?: ($staff->nip ?: $staff->npk))),
                            'classification' => EmisReconciliationItem::CLASS_SAME,
                            'differences' => [],
                            'pintu_snapshot' => $this->staffSnapshot($staff),
                            'emis_snapshot' => $this->staffStagingSnapshot($staging),
                        ]);
                    } else {
                        $diffCount++;
                        EmisReconciliationItem::create([
                            'batch_id' => $batch->id,
                            'entity_type' => 'staff',
                            'entity_id' => $staff->id,
                            'staging_id' => $staging->id,
                            'external_reference_id' => $staff->emis_gtk_id ?: ($staff->nik ?: ($staff->nuptk ?: ($staff->nip ?: $staff->npk))),
                            'classification' => EmisReconciliationItem::CLASS_DIFFERENT,
                            'differences' => $differences,
                            'pintu_snapshot' => $this->staffSnapshot($staff),
                            'emis_snapshot' => $this->staffStagingSnapshot($staging),
                            'review_notes' => 'Ditemukan perbedaan atribut antara master PINTU dan EMIS GTK. Master PINTU dipertahankan sebagai SSOT.',
                        ]);
                    }
                }
            });

            $pintuOnlyCount = 0;
            foreach ($staffMembers as $staff) {
                if (isset($matchedStaffIds[$staff->id])) {
                    continue;
                }

                $pintuOnlyCount++;
                EmisReconciliationItem::create([
                    'batch_id' => $batch->id,
                    'entity_type' => 'staff',
                    'entity_id' => $staff->id,
                    'staging_id' => null,
                    'external_reference_id' => $staff->emis_gtk_id ?: ($staff->nik ?: ($staff->nuptk ?: ($staff->nip ?: $staff->npk))),
                    'classification' => EmisReconciliationItem::CLASS_PINTU_ONLY,
                    'differences' => null,
                    'pintu_snapshot' => $this->staffSnapshot($staff),
                    'emis_snapshot' => null,
                    'review_notes' => 'Data ada di master PINTU namun tidak ditemukan pada dataset pembanding EMIS GTK.',
                ]);
            }

            $batch->update([
                'total_rows' => $totalRows,
                'matched_identical_count' => $sameCount,
                'difference_count' => $diffCount,
                'pintu_only_count' => $pintuOnlyCount,
                'emis_only_count' => $emisOnlyCount,
                'needs_review_count' => $needsReviewCount,
            ]);

            return $batch->fresh(['items']);
        });
    }

    /**
     * Bandingkan atribut Student PINTU dengan baris EmisStaging.
     * Membedakan field yang tidak dikirim pada payload EMIS vs field yang sengaja kosong/null vs nilai berbeda.
     *
     * @return array<string, array{pintu_value: mixed, emis_value: mixed, diff_type?: string}>
     */
    public function compareStudentWithStaging(Student $student, EmisStaging $staging): array
    {
        $differences = [];

        foreach (self::STUDENT_RECONCILIATION_FIELDS as $field) {
            if (! $this->studentStagingHasFieldProvided($staging, $field)) {
                continue;
            }

            $emisRaw = $this->extractStudentStagingValue($staging, $field);
            $pintuVal = $this->normalizeComparableValue($student->{$field});
            $emisVal = $this->normalizeComparableValue($emisRaw);

            if ($pintuVal !== $emisVal) {
                $diffType = match (true) {
                    $pintuVal !== null && $emisVal === null => 'emis_missing_value',
                    $pintuVal === null && $emisVal !== null => 'pintu_missing_value',
                    default => 'value_mismatch',
                };

                $differences[$field] = [
                    'pintu_value' => $pintuVal,
                    'emis_value' => $emisVal,
                    'diff_type' => $diffType,
                ];
            }
        }

        return $differences;
    }

    /**
     * Bandingkan atribut Staff PINTU dengan baris EmisGtkStaging.
     * Membedakan field yang tidak dikirim pada payload EMIS vs field yang sengaja kosong/null vs nilai berbeda.
     *
     * @return array<string, array{pintu_value: mixed, emis_value: mixed, diff_type?: string}>
     */
    public function compareStaffWithStaging(Staff $staff, EmisGtkStaging $staging): array
    {
        $rawPayload = is_array($staging->raw_data) ? $staging->raw_data : [];
        $differences = [];

        foreach (self::STAFF_RECONCILIATION_FIELDS as $field) {
            $emisRaw = $staging->{$field};

            if ($emisRaw === null && ! array_key_exists($field, $rawPayload)) {
                continue;
            }

            $pintuVal = $this->normalizeComparableValue($staff->{$field});
            $emisVal = $this->normalizeComparableValue($emisRaw);

            if ($pintuVal !== $emisVal) {
                $diffType = match (true) {
                    $pintuVal !== null && $emisVal === null => 'emis_missing_value',
                    $pintuVal === null && $emisVal !== null => 'pintu_missing_value',
                    default => 'value_mismatch',
                };

                $differences[$field] = [
                    'pintu_value' => $pintuVal,
                    'emis_value' => $emisVal,
                    'diff_type' => $diffType,
                ];
            }
        }

        return $differences;
    }

    /**
     * Catat daftar perubahan master PINTU yang harus diperbarui oleh operator ke EMIS.
     *
     * @param  array<string, array{old_value: mixed, new_value: mixed}>  $changedFields
     */
    public function recordOutboundUpdateTask(
        string $entityType,
        string $entityId,
        string $sourceType,
        ?string $sourceId,
        array $changedFields,
        ?string $actorId = null,
    ): ?EmisUpdateTask {
        $emisBoundChanges = [];

        foreach ($changedFields as $field => $pair) {
            if (! $this->ownershipService->requiresOutboundEmisUpdate($entityType, (string) $field)) {
                continue;
            }

            $oldVal = $this->normalizeComparableValue($pair['old_value'] ?? null);
            $newVal = $this->normalizeComparableValue($pair['new_value'] ?? null);

            if ($oldVal === $newVal) {
                continue;
            }

            $emisBoundChanges[$field] = [
                'old_value' => $oldVal,
                'new_value' => $newVal,
            ];
        }

        if ($emisBoundChanges === []) {
            return null;
        }

        return EmisUpdateTask::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'changed_fields' => $emisBoundChanges,
            'status' => EmisUpdateTask::STATUS_PENDING,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Perbarui status pembaruan EMIS oleh operator tanpa mengubah data master PINTU.
     */
    public function updateTaskStatus(
        EmisUpdateTask $task,
        string $status,
        ?string $operatorNotes,
        ?string $confirmationReference,
        User $actor,
    ): EmisUpdateTask {
        if (! in_array($status, EmisUpdateTask::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Status pembaruan EMIS [{$status}] tidak valid.");
        }

        return DB::transaction(function () use ($task, $status, $operatorNotes, $confirmationReference, $actor): EmisUpdateTask {
            $lockedTask = EmisUpdateTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedTask->status;

            $lockedTask->update([
                'status' => $status,
                'operator_notes' => $operatorNotes,
                'confirmation_reference' => $confirmationReference,
                'updated_by' => $actor->id,
                'external_updated_at' => in_array($status, [EmisUpdateTask::STATUS_COMPLETED, EmisUpdateTask::STATUS_FAILED], true)
                    ? now()
                    : $lockedTask->external_updated_at,
            ]);

            ActivityLog::record(
                action: 'emis_outbound_status_updated',
                model: $lockedTask,
                before: ['status' => $oldStatus],
                after: [
                    'status' => $status,
                    'entity_type' => $lockedTask->entity_type,
                    'entity_id' => $lockedTask->entity_id,
                    'operator_notes' => $operatorNotes,
                    'confirmation_reference' => $confirmationReference,
                ],
            );

            return $lockedTask->fresh();
        });
    }

    /**
     * Bangun indeks hash O(1) untuk pencocokan Siswa berdasarkan prioritas:
     * 1. emis_student_id, 2. nisn, 3. nik.
     *
     * @param  Collection<int, Student>  $students
     * @return array{emis_student_id: array<string, Student>, nisn: array<string, Student>, nik: array<string, Student>}
     */
    private function buildStudentLookupIndex(Collection $students): array
    {
        $index = [
            'emis_student_id' => [],
            'nisn' => [],
            'nik' => [],
        ];

        foreach ($students as $student) {
            foreach (['emis_student_id', 'nisn', 'nik'] as $key) {
                $val = trim((string) $student->{$key});
                if ($val !== '') {
                    $index[$key][$val] = $student;
                }
            }
        }

        return $index;
    }

    /**
     * Bangun indeks hash O(1) untuk pencocokan GTK berdasarkan prioritas:
     * 1. emis_gtk_id, 2. nik, 3. nuptk, 4. nip, 5. npk.
     *
     * @param  Collection<int, Staff>  $staffMembers
     * @return array{emis_gtk_id: array<string, Staff>, nik: array<string, Staff>, nuptk: array<string, Staff>, nip: array<string, Staff>, npk: array<string, Staff>}
     */
    private function buildStaffLookupIndex(Collection $staffMembers): array
    {
        $index = [
            'emis_gtk_id' => [],
            'nik' => [],
            'nuptk' => [],
            'nip' => [],
            'npk' => [],
        ];

        foreach ($staffMembers as $staff) {
            foreach (['emis_gtk_id', 'nik', 'nuptk', 'nip', 'npk'] as $key) {
                $val = trim((string) $staff->{$key});
                if ($val !== '') {
                    $index[$key][$val] = $staff;
                }
            }
        }

        return $index;
    }

    /**
     * Pencocokan Siswa dengan urutan prioritas eksplisit:
     * 1. emis_student_id
     * 2. nisn
     * 3. nik
     *
     * Jika kunci identitas merujuk ke lebih dari satu kandidat Siswa berbeda di PINTU,
     * baris diklasifikasikan sebagai needs_review.
     *
     * @param  array{emis_student_id: array<string, Student>, nisn: array<string, Student>, nik: array<string, Student>}  $studentIndex
     * @return array{status: string, student: ?Student, reason?: string, differences?: array<string, mixed>}
     */
    private function resolveStudentMatch(EmisStaging $staging, array $studentIndex): array
    {
        $emisStudentId = trim((string) $this->extractStudentStagingValue($staging, 'emis_student_id'));
        $nisn = trim((string) $staging->nisn);
        $nik = trim((string) $staging->nik);

        if ($emisStudentId === '' && $nisn === '' && $nik === '') {
            return [
                'status' => 'needs_review',
                'student' => null,
                'reason' => 'Baris staging EMIS Siswa tidak memiliki emis_student_id, NISN, maupun NIK.',
            ];
        }

        $matches = collect([
            'emis_student_id' => $emisStudentId !== '' ? ($studentIndex['emis_student_id'][$emisStudentId] ?? null) : null,
            'nisn' => $nisn !== '' ? ($studentIndex['nisn'][$nisn] ?? null) : null,
            'nik' => $nik !== '' ? ($studentIndex['nik'][$nik] ?? null) : null,
        ])->filter();

        $uniqueStudentIds = $matches->map(fn (Student $s): string => $s->id)->unique()->values();

        if ($uniqueStudentIds->count() > 1) {
            return [
                'status' => 'needs_review',
                'student' => $matches->first(),
                'reason' => 'Konflik identitas: kunci identitas (emis_student_id/NISN/NIK) pada staging EMIS merujuk ke lebih dari satu siswa berbeda di master PINTU.',
            ];
        }

        return [
            'status' => 'resolved',
            'student' => $matches->first(),
        ];
    }

    /**
     * @param  array{emis_gtk_id: array<string, Staff>, nik: array<string, Staff>, nuptk: array<string, Staff>, nip: array<string, Staff>, npk: array<string, Staff>}  $staffIndex
     * @return array{status: string, staff: ?Staff, reason?: string, differences?: array<string, mixed>}
     */
    private function resolveStaffMatch(EmisGtkStaging $staging, array $staffIndex): array
    {
        $emisGtkId = trim((string) $staging->emis_gtk_id);
        $nik = trim((string) $staging->nik);
        $nuptk = trim((string) $staging->nuptk);
        $nip = trim((string) $staging->nip);
        $npk = trim((string) $staging->npk);

        if ($nik === '' && $nuptk === '' && $nip === '' && $npk === '') {
            return [
                'status' => 'needs_review',
                'staff' => null,
                'reason' => 'Baris staging EMIS GTK tidak memiliki kunci identitas (NIK, NUPTK, NIP, atau NPK).',
            ];
        }

        $matches = collect([
            'emis_gtk_id' => $emisGtkId !== '' ? ($staffIndex['emis_gtk_id'][$emisGtkId] ?? null) : null,
            'nik' => $nik !== '' ? ($staffIndex['nik'][$nik] ?? null) : null,
            'nuptk' => $nuptk !== '' ? ($staffIndex['nuptk'][$nuptk] ?? null) : null,
            'nip' => $nip !== '' ? ($staffIndex['nip'][$nip] ?? null) : null,
            'npk' => $npk !== '' ? ($staffIndex['npk'][$npk] ?? null) : null,
        ])->filter();

        $uniqueStaffIds = $matches->map(fn (Staff $s): string => $s->id)->unique()->values();

        if ($uniqueStaffIds->count() > 1) {
            return [
                'status' => 'needs_review',
                'staff' => $matches->first(),
                'reason' => 'Konflik identitas: kunci identitas pada staging EMIS GTK merujuk ke lebih dari satu GTK di master PINTU.',
            ];
        }

        return [
            'status' => 'resolved',
            'staff' => $matches->first(),
        ];
    }

    /**
     * Periksa apakah sebuah field Siswa benar-benar dikirim pada baris staging EMIS
     * (baik melalui kolom top-level maupun key eksplisit di dalam raw_data).
     */
    private function studentStagingHasFieldProvided(EmisStaging $staging, string $field): bool
    {
        $direct = $staging->getAttribute($field);
        if ($direct !== null && $direct !== '') {
            return true;
        }

        $raw = is_array($staging->raw_data) ? $staging->raw_data : [];
        if (array_key_exists($field, $raw)) {
            return true;
        }

        return match ($field) {
            'emis_student_id' => array_key_exists('id', $raw) || array_key_exists('student_id', $raw),
            'kk_number' => array_key_exists('no_kk', $raw) || (is_array($raw['family'] ?? null) && array_key_exists('no_kk', $raw['family'])),
            'birth_place' => is_array($raw['personal'] ?? null) && array_key_exists('tempat_lahir', $raw['personal']),
            'birth_date' => is_array($raw['personal'] ?? null) && array_key_exists('tanggal_lahir', $raw['personal']),
            'mother_name' => is_array($raw['parents'] ?? null) && array_key_exists('ibu_nama', $raw['parents']),
            'father_name' => is_array($raw['parents'] ?? null) && array_key_exists('ayah_nama', $raw['parents']),
            'address' => is_array($raw['address'] ?? null) && array_key_exists('alamat', $raw['address']),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function studentSnapshot(Student $student): array
    {
        return [
            'id' => $student->id,
            'emis_student_id' => $student->emis_student_id,
            'nisn' => $student->nisn,
            'nik' => $student->nik,
            'name' => $student->name,
            'gender' => $student->gender,
            'birth_place' => $student->birth_place,
            'birth_date' => $this->normalizeComparableValue($student->birth_date),
            'mother_name' => $student->mother_name,
            'father_name' => $student->father_name,
            'address' => $student->address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function studentStagingSnapshot(EmisStaging $staging): array
    {
        return [
            'staging_id' => $staging->id,
            'nisn' => $this->extractStudentStagingValue($staging, 'nisn'),
            'nik' => $this->extractStudentStagingValue($staging, 'nik'),
            'name' => $this->extractStudentStagingValue($staging, 'name'),
            'gender' => $this->extractStudentStagingValue($staging, 'gender'),
            'birth_place' => $this->extractStudentStagingValue($staging, 'birth_place'),
            'birth_date' => $this->normalizeComparableValue($this->extractStudentStagingValue($staging, 'birth_date')),
            'mother_name' => $this->extractStudentStagingValue($staging, 'mother_name'),
            'father_name' => $this->extractStudentStagingValue($staging, 'father_name'),
            'address' => $this->extractStudentStagingValue($staging, 'address'),
        ];
    }

    private function extractStudentStagingValue(EmisStaging $staging, string $field): mixed
    {
        $direct = $staging->getAttribute($field);
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        $raw = is_array($staging->raw_data) ? $staging->raw_data : [];

        return match ($field) {
            'emis_student_id' => $raw['emis_student_id'] ?? $raw['id'] ?? $raw['student_id'] ?? null,
            'kk_number' => $raw['kk_number'] ?? $raw['no_kk'] ?? data_get($raw, 'family.no_kk'),
            'birth_place' => $raw['birth_place'] ?? data_get($raw, 'personal.tempat_lahir'),
            'birth_date' => $raw['birth_date'] ?? data_get($raw, 'personal.tanggal_lahir'),
            'mother_name' => $raw['mother_name'] ?? data_get($raw, 'parents.ibu_nama'),
            'father_name' => $raw['father_name'] ?? data_get($raw, 'parents.ayah_nama'),
            'address' => $raw['address'] ?? data_get($raw, 'address.alamat'),
            default => $raw[$field] ?? null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function staffSnapshot(Staff $staff): array
    {
        return [
            'id' => $staff->id,
            'emis_gtk_id' => $staff->emis_gtk_id,
            'nik' => $staff->nik,
            'nip' => $staff->nip,
            'nuptk' => $staff->nuptk,
            'npk' => $staff->npk,
            'full_name_with_titles' => $staff->full_name_with_titles,
            'gender' => $staff->gender,
            'birth_place' => $staff->birth_place,
            'birth_date' => $this->normalizeComparableValue($staff->birth_date),
            'employment_status' => $staff->employment_status,
            'civil_rank' => $staff->civil_rank,
            'civil_rank_tmt' => $this->normalizeComparableValue($staff->civil_rank_tmt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffStagingSnapshot(EmisGtkStaging $staging): array
    {
        return [
            'staging_id' => $staging->id,
            'emis_gtk_id' => $staging->emis_gtk_id,
            'nik' => $staging->nik,
            'nip' => $staging->nip,
            'nuptk' => $staging->nuptk,
            'npk' => $staging->npk,
            'full_name_with_titles' => $staging->full_name_with_titles,
            'gender' => $staging->gender,
            'birth_place' => $staging->birth_place,
            'birth_date' => $this->normalizeComparableValue($staging->birth_date),
            'employment_status' => $staging->employment_status,
            'civil_rank' => $staging->civil_rank,
            'civil_rank_tmt' => $this->normalizeComparableValue($staging->civil_rank_tmt),
        ];
    }

    private function normalizeComparableValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
