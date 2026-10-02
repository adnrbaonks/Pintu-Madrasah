<?php

namespace App\Observers;

use App\Models\Student;
use App\Support\Webhook;

class StudentObserver
{
    public function created(Student $student): void
    {
        Webhook::fire('student.created', Webhook::studentPayload($student));
    }

    public function updated(Student $student): void
    {
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
            Webhook::fire('student.mutated', Webhook::studentPayload($student, [
                'reason' => $changes['status'],
            ]));
            return;
        }

        Webhook::fire('student.updated', Webhook::studentPayload($student, [
            'changed_fields' => array_keys($changes),
        ]));
    }

    public function deleted(Student $student): void
    {
        Webhook::fire('student.mutated', Webhook::studentPayload($student, [
            'reason' => 'deleted',
        ]));
    }
}
