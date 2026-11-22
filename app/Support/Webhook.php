<?php

namespace App\Support;

use App\Jobs\DispatchWebhookJob;
use App\Models\Staff;
use App\Models\Student;
use App\Models\WebhookSubscription;

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
        // Jangan banjiri antrean bila tak ada pelanggan event ini
        // (commit massal 700 siswa = 700 job sia-sia tanpa guard ini).
        $hasSub = WebhookSubscription::where('is_active', true)
            ->where('event', $event)
            ->exists();

        if (! $hasSub) {
            return;
        }

        DispatchWebhookJob::dispatch($event, $data)->onQueue('webhooks');
    }

    public static function studentPayload(Student $student, array $extra = []): array
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

    public static function staffPayload(Staff $staff, array $extra = []): array
    {
        return array_merge([
            'id' => $staff->id,
            'nip' => $staff->nip,
            'nuptk' => $staff->nuptk,
            'name' => $staff->full_name_with_titles,
            'employment_status' => $staff->employment_status,
            'functional_position' => $staff->functional_position,
            'is_active' => $staff->is_active,
        ], $extra);
    }
}
