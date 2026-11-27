<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Staff;
use App\Support\Webhook;
use Illuminate\Support\Facades\DB;

class StaffService
{
    public function __construct(
        private DataOwnershipService $dataOwnershipService,
    ) {}

    public function update(
        Staff $staff,
        array $attributes,
        ?string $actorUserId = null
    ): bool {
        if (empty($attributes)) {
            return false;
        }

        return DB::transaction(function () use ($staff, $attributes, $actorUserId): bool {
            $filtered = $this->dataOwnershipService->filterAllowedAttributes('staff', $attributes);
            if ($filtered === []) {
                return false;
            }

            $oldValues = $this->captureSnapshot($staff);
            $staff->update($filtered);
            $changedFields = $this->logChange($staff, $filtered, $oldValues, $actorUserId);

            if (! empty($changedFields)) {
                $changedPairs = [];
                foreach ($changedFields as $changedField) {
                    $changedPairs[$changedField] = [
                        'old_value' => $oldValues[$changedField] ?? null,
                        'new_value' => $staff->getAttribute($changedField),
                    ];
                }

                app(EmisReconciliationService::class)->recordOutboundUpdateTask(
                    entityType: 'staff',
                    entityId: $staff->id,
                    sourceType: 'direct_update',
                    sourceId: null,
                    changedFields: $changedPairs,
                    actorId: $actorUserId ?? (auth()->id() ? (string) auth()->id() : null),
                );

                $payload = Webhook::staffPayload($staff, [
                    'changed_fields' => $changedFields,
                ]);

                app(OutboxService::class)->record(
                    aggregateType: Staff::class,
                    aggregateId: $staff->id,
                    eventType: 'staff.updated',
                    payload: $payload,
                );
            }

            return true;
        });
    }

    /**
     * @return array<string, mixed|null>
     */
    private function captureSnapshot(Staff $staff): array
    {
        return [
            'full_name_with_titles' => $staff->full_name_with_titles,
            'first_title' => $staff->first_title,
            'last_title' => $staff->last_title,
            'phone' => $staff->phone,
            'email' => $staff->email,
            'employment_status' => $staff->employment_status,
            'functional_position' => $staff->functional_position,
            'highest_education' => $staff->highest_education,
            'university_name' => $staff->university_name,
            'study_program' => $staff->study_program,
            'graduation_year' => $staff->graduation_year,
            'is_active' => $staff->is_active,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function logChange(
        Staff $staff,
        array $newValues,
        array $oldValues,
        ?string $actorUserId = null
    ): array {
        $before = [];
        $after = [];

        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;
            if ($oldValue !== $newValue) {
                $before[$key] = $oldValue;
                $after[$key] = $newValue;
            }
        }

        if (! empty($before)) {
            ActivityLog::create([
                'user_id' => $actorUserId ?? auth()->id(),
                'action' => 'updated',
                'model_type' => Staff::class,
                'model_id' => $staff->id,
                'before_payload' => $before,
                'after_payload' => $after,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }

        return array_keys($before);
    }
}
