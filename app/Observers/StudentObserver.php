<?php

namespace App\Observers;

use App\Models\Student;
use App\Services\OutboxService;
use App\Support\Webhook;

class StudentObserver
{
    public function __construct(
        private OutboxService $outboxService,
    ) {}

    public function created(Student $student): void
    {
        if (OutboxService::isSuppressed()) {
            return;
        }

        $this->outboxService->record(
            aggregateType: Student::class,
            aggregateId: $student->id,
            eventType: 'student.created',
            payload: Webhook::studentPayload($student),
        );
    }

    public function updated(Student $student): void
    {
        if (OutboxService::isSuppressed()) {
            return;
        }

        $changes = $student->getChanges();
        unset($changes['updated_at']);

        if (empty($changes)) {
            return;
        }

        // Status keluar/lulus/dikeluarkan = event mutasi (nonaktifkan presensi)
        if (
            isset($changes['status'])
            && in_array($changes['status'], ['mutated_out', 'dropped', 'graduated'], true)
        ) {
            $this->outboxService->record(
                aggregateType: Student::class,
                aggregateId: $student->id,
                eventType: 'student.mutated',
                payload: Webhook::studentPayload($student, [
                    'reason' => $changes['status'],
                ]),
            );

            return;
        }

        $this->outboxService->record(
            aggregateType: Student::class,
            aggregateId: $student->id,
            eventType: 'student.updated',
            payload: Webhook::studentPayload($student, [
                'changed_fields' => array_keys($changes),
            ]),
        );
    }

    public function deleted(Student $student): void
    {
        if (OutboxService::isSuppressed()) {
            return;
        }

        $this->outboxService->record(
            aggregateType: Student::class,
            aggregateId: $student->id,
            eventType: 'student.mutated',
            payload: Webhook::studentPayload($student, [
                'reason' => 'deleted',
            ]),
        );
    }
}
