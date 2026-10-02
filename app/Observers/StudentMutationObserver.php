<?php

namespace App\Observers;

use App\Models\StudentMutation;
use App\Support\Webhook;

class StudentMutationObserver
{
    public function created(StudentMutation $mutation): void
    {
        $student = $mutation->student;

        Webhook::fire('student.mutated', $student
            ? Webhook::studentPayload($student, [
                'reason' => $mutation->type ?? 'mutated_out',
                'mutation_id' => $mutation->id,
            ])
            : [
                'mutation_id' => $mutation->id,
                'reason' => $mutation->type ?? 'mutated_out',
            ]);
    }
}
