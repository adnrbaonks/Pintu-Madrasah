<?php

namespace App\Observers;

use App\Models\StudentMutation;
use App\Support\Webhook;

class StudentMutationObserver
{
    /**
     * Mutasi keluar diliput StudentObserver (perubahan status siswa).
     * Di sini hanya: mutasi MASUK yang selesai = siswa aktif baru
     * (antrean kartu RFID SIPSMAD).
     */
    public function updated(StudentMutation $mutation): void
    {
        if ($mutation->wasChanged('status')
            && $mutation->status === 'completed'
            && $mutation->type === 'in'
            && $mutation->student
        ) {
            Webhook::fire('student.created', Webhook::studentPayload($mutation->student, [
                'via' => 'mutation_in',
                'mutation_id' => $mutation->id,
            ]));
        }
    }
}
