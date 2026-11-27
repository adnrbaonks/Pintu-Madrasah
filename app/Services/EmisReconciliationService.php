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

            $stagings = EmisStaging::query()->orderBy('created_at')->get();
            $students = Student::query()->get();

            $matchedStudentIds = [];
            $sameCount = 0;
            $diffCount = 0;
            $emisOnlyCount = 0;
            $needsReviewCount = 0;

            foreach ($stagings as $staging) {
                $resolution = $this->resolveStudentMatch($staging, $students);

                if ($resolution['status'] === 'needs_review') {
                    $needsReviewCount++;
                    EmisReconciliationItem::create([
                        'batch_id' => $batch->id,
                        'entity_type' => 'student',
                        'entity_id' => $resolution['student']?->id,
                        'staging_id' => $staging->id,
                        'external_reference_id' => $staging->nisn,
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
                        'external_reference_id' => $staging->nisn,
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
                'total_rows' => $stagings->count(),
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

            $stagings = EmisGtkStaging::query()->orderBy('created_at')->get();
            $staffMembers = Staff::query()->get();

            $matchedStaffIds = [];
            $sameCount = 0;
            $diffCount = 0;
            $emisOnlyCount = 0;
            $needsReviewCount = 0;

            foreach ($stagings as $staging) {
                $resolution = $this->resolveStaffMatch($staging, $staffMembers);

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
                'total_rows' => $stagings->count(),
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
     *
     * @return array<string, array{pintu_value: mixed, emis_value: mixed}>
     */
    public function compareStudentWithStaging(Student $student, EmisStaging $staging): array
    {
        $fields = ['nisn', 'nik', 'name', 'gender', 'birth_place', 'birth_date', 'mother_name', 'father_name', 'address'];
        $differences = [];

        foreach ($fields as $field) {
            $emisRaw = $this->extractStudentStagingValue($staging, $field);
            if ($emisRaw === null || $emisRaw === '') {
                continue;
            }

            $pintuVal = $this->normalizeComparableValue($student->{$field});
            $emisVal = $this->normalizeComparableValue($emisRaw);

            if ($pintuVal !== $emisVal) {
                $differences[$field] = [
                    'pintu_value' => $pintuVal,
                    'emis_value' => $emisVal,
                ];
            }
        }

        return $differences;
    }

    /**
     * Bandingkan atribut Staff PINTU dengan baris EmisGtkStaging.
     *
     * @return array<string, array{pintu_value: mixed, emis_value: mixed}>
     */
    public function compareStaffWithStaging(Staff $staff, EmisGtkStaging $staging): array
    {
        $fields = [
            'nik', 'nip', 'nuptk', 'npk',
            'full_name_with_titles', 'first_title', 'last_title',
            'gender', 'birth_place', 'birth_date',
            'phone', 'email',
            'employment_status', 'civil_rank', 'civil_rank_tmt',
            'functional_position',
            'highest_education', 'university_name', 'study_program', 'graduation_year',
        ];

        $rawPayload = is_array($staging->raw_data) ? $staging->raw_data : [];
        $differences = [];

        foreach ($fields as $field) {
            $emisRaw = $staging->{$field};

            if ($emisRaw === null && ! array_key_exists($field, $rawPayload)) {
                continue;
            }

            $pintuVal = $this->normalizeComparableValue($staff->{$field});
            $emisVal = $this->normalizeComparableValue($emisRaw);

            if ($pintuVal !== $emisVal) {
                $differences[$field] = [
                    'pintu_value' => $pintuVal,
                    'emis_value' => $emisVal,
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
     * @param  Collection<int, Student>  $students
     * @return array{status: string, student: ?Student, reason?: string, differences?: array<string, mixed>}
     */
    private function resolveStudentMatch(EmisStaging $staging, $students): array
    {
        $nisn = trim((string) $staging->nisn);
        $nik = trim((string) $staging->nik);

        if ($nisn === '' && $nik === '') {
            return [
                'status' => 'needs_review',
                'student' => null,
                'reason' => 'Baris staging EMIS Siswa tidak memiliki NISN maupun NIK.',
            ];
        }

        $byNisn = $nisn !== '' ? $students->firstWhere('nisn', $nisn) : null;
        $byNik = $nik !== '' ? $students->firstWhere('nik', $nik) : null;

        if ($byNisn && $byNik && $byNisn->id !== $byNik->id) {
            return [
                'status' => 'needs_review',
                'student' => $byNisn,
                'reason' => 'Konflik identitas: NISN dan NIK pada staging EMIS merujuk ke dua siswa berbeda di master PINTU.',
            ];
        }

        return [
            'status' => 'resolved',
            'student' => $byNisn ?? $byNik,
        ];
    }

    /**
     * @param  Collection<int, Staff>  $staffMembers
     * @return array{status: string, staff: ?Staff, reason?: string, differences?: array<string, mixed>}
     */
    private function resolveStaffMatch(EmisGtkStaging $staging, $staffMembers): array
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
            'emis_gtk_id' => $emisGtkId !== '' ? $staffMembers->firstWhere('emis_gtk_id', $emisGtkId) : null,
            'nik' => $nik !== '' ? $staffMembers->firstWhere('nik', $nik) : null,
            'nuptk' => $nuptk !== '' ? $staffMembers->firstWhere('nuptk', $nuptk) : null,
            'nip' => $nip !== '' ? $staffMembers->firstWhere('nip', $nip) : null,
            'npk' => $npk !== '' ? $staffMembers->firstWhere('npk', $npk) : null,
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
