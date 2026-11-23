<?php

namespace App\Observers;

use App\Models\Staff;
use App\Services\OutboxService;
use App\Support\Webhook;

class StaffObserver
{
    public function __construct(
        private OutboxService $outboxService,
    ) {}

    public function created(Staff $staff): void
    {
        if (OutboxService::isSuppressed()) {
            return;
        }

        $this->outboxService->record(
            aggregateType: Staff::class,
            aggregateId: $staff->id,
            eventType: 'staff.created',
            payload: Webhook::staffPayload($staff),
        );
    }

    public function updated(Staff $staff): void
    {
        if (OutboxService::isSuppressed()) {
            return;
        }

        $changes = $staff->getChanges();
        unset($changes['updated_at']);

        if (empty($changes)) {
            return;
        }

        $payload = Webhook::staffPayload($staff, [
            'changed_fields' => array_keys($changes),
        ]);

        $this->outboxService->record(
            aggregateType: Staff::class,
            aggregateId: $staff->id,
            eventType: 'staff.updated',
            payload: $payload,
        );

        if (array_key_exists('is_active', $changes) && ! $staff->is_active) {
            $this->outboxService->record(
                aggregateType: Staff::class,
                aggregateId: $staff->id,
                eventType: 'staff.deactivated',
                payload: $payload,
            );
        }
    }
}
