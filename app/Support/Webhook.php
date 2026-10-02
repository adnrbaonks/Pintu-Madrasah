<?php

namespace App\Support;

use App\Jobs\DispatchWebhookJob;

/**
 * Webhook — pengirim event ke aplikasi satelit (SIPSMAD, PTSP, WA Gateway).
 *
 * Event: student.created | student.updated | student.mutated | student.promoted
 * Payload selalu: ['event' => ..., 'occurred_at' => ..., 'data' => [...]]
 */
class Webhook
{
    public static function fire(string $event, array $data): void
    {
        DispatchWebhookJob::dispatch($event, $data)->onQueue('webhooks');
    }

    public static function studentPayload(\App\Models\Student $student, array $extra = []): array
    {
        return array_merge([
            'id' => $student->id,
            'nisn' => $student->nisn,
            'nik' => $student->nik,
            'name' => $student->name,
            'gender' => $student->gender,
            'status' => $student->status,
            'grade_level' => $student->grade_level,
            'rombel' => $student->currentCohort?->rombel?->name,
        ], $extra);
    }
}
