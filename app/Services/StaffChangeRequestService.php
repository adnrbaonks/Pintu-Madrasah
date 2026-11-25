<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Staff;
use App\Models\StaffChangeApproval;
use App\Models\StaffChangeAttachment;
use App\Models\StaffChangeHistory;
use App\Models\StaffChangeRequest;
use App\Models\User;
use App\Notifications\ChangeRequestApplied;
use App\Notifications\ChangeRequestApproved;
use App\Notifications\ChangeRequestRejected;
use App\Notifications\ChangeRequestSubmitted;
use App\Notifications\RevisionRequested;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffChangeRequestService
{
    public function __construct(
        private DataOwnershipService $dataOwnershipService,
        private OrganizationService $organizationService,
    ) {}

    /**
     * Buat draf pengajuan perubahan data GTK beserta item perubahan dan lampiran bukti.
     *
     * @param  array<string, mixed>  $proposedChanges
     * @param  array<int, UploadedFile>  $attachments
     */
    public function createDraft(
        Staff $staff,
        User $requester,
        array $proposedChanges,
        ?string $reason = null,
        array $attachments = [],
    ): StaffChangeRequest {
        $normalizedItems = $this->buildNormalizedItems($staff, $proposedChanges);

        if (empty($normalizedItems)) {
            throw ValidationException::withMessages([
                'changes' => 'Pilih minimal satu perubahan data yang berbeda dari nilai saat ini.',
            ]);
        }

        return DB::transaction(function () use ($staff, $requester, $normalizedItems, $reason, $attachments): StaffChangeRequest {
            $changeRequest = StaffChangeRequest::create([
                'staff_id' => $staff->id,
                'requester_user_id' => $requester->id,
                'status' => StaffChangeRequest::STATUS_DRAFT,
                'reason' => $reason,
                'current_stage' => 'draft',
                'created_by' => $requester->id,
            ]);

            foreach ($normalizedItems as $itemData) {
                $changeRequest->items()->create($itemData);
            }

            $this->storeAttachments($changeRequest, $attachments, $requester->id);

            ActivityLog::create([
                'user_id' => $requester->id,
                'action' => 'created_staff_change_request',
                'model_type' => StaffChangeRequest::class,
                'model_id' => $changeRequest->id,
                'before_payload' => null,
                'after_payload' => [
                    'staff_id' => $staff->id,
                    'status' => $changeRequest->status,
                    'fields' => array_column($normalizedItems, 'field'),
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $changeRequest->load(['items', 'attachments', 'approvals', 'histories']);
        });
    }

    /**
     * Perbarui draf atau pengajuan yang diminta revisi.
     *
     * @param  array<string, mixed>  $proposedChanges
     * @param  array<int, UploadedFile>  $attachments
     */
    public function updateDraft(
        StaffChangeRequest $changeRequest,
        User $actor,
        array $proposedChanges,
        ?string $reason = null,
        array $attachments = [],
    ): StaffChangeRequest {
        $this->ensureStatusIn($changeRequest, [
            StaffChangeRequest::STATUS_DRAFT,
            StaffChangeRequest::STATUS_REVISION_REQUESTED,
        ], 'Hanya pengajuan berstatus draf atau revisi yang dapat diubah.');

        $staff = $changeRequest->staff()->firstOrFail();
        $normalizedItems = $this->buildNormalizedItems($staff, $proposedChanges);

        if (empty($normalizedItems)) {
            throw ValidationException::withMessages([
                'changes' => 'Pilih minimal satu perubahan data yang berbeda dari nilai saat ini.',
            ]);
        }

        return DB::transaction(function () use ($changeRequest, $actor, $normalizedItems, $reason, $attachments): StaffChangeRequest {
            $changeRequest->update([
                'reason' => $reason ?? $changeRequest->reason,
            ]);

            $changeRequest->items()->delete();
            foreach ($normalizedItems as $itemData) {
                $changeRequest->items()->create($itemData);
            }

            $this->storeAttachments($changeRequest, $attachments, $actor->id);

            return $changeRequest->fresh(['items', 'attachments', 'approvals', 'histories']);
        });
    }

    /**
     * Ajukan draf perubahan data untuk diverifikasi oleh Tata Usaha (Kaur TU / Operator).
     */
    public function submit(StaffChangeRequest $changeRequest, User $actor): StaffChangeRequest
    {
        return DB::transaction(function () use ($changeRequest, $actor): StaffChangeRequest {
            $lockedRequest = StaffChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureStatusIn($lockedRequest, [
                StaffChangeRequest::STATUS_DRAFT,
                StaffChangeRequest::STATUS_REVISION_REQUESTED,
            ], 'Pengajuan ini tidak dapat dikirim karena bukan berstatus draf atau revisi.');

            if ($lockedRequest->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Pengajuan perubahan data harus memiliki minimal satu field yang diajukan.',
                ]);
            }

            $beforeStatus = $lockedRequest->status;

            $lockedRequest->update([
                'status' => StaffChangeRequest::STATUS_UNDER_TU_REVIEW,
                'current_stage' => 'tu_review',
                'submitted_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => 'submitted_staff_change_request',
                'model_type' => StaffChangeRequest::class,
                'model_id' => $lockedRequest->id,
                'before_payload' => ['status' => $beforeStatus],
                'after_payload' => [
                    'status' => $lockedRequest->status,
                    'current_stage' => $lockedRequest->current_stage,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $verifiers = $this->resolveStageRecipients('tu_review', $actor->id);
            if ($verifiers->isNotEmpty()) {
                DB::afterCommit(function () use ($verifiers, $lockedRequest): void {
                    Notification::send($verifiers, new ChangeRequestSubmitted($lockedRequest));
                });
            }

            return $lockedRequest->fresh(['items', 'attachments', 'approvals', 'histories']);
        });
    }

    /**
     * Batalkan pengajuan oleh pemohon (hanya jika belum diputuskan / masih draf, revisi, atau review TU).
     */
    public function cancel(StaffChangeRequest $changeRequest, User $actor): void
    {
        DB::transaction(function () use ($changeRequest, $actor): void {
            $lockedRequest = StaffChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureStatusIn($lockedRequest, [
                StaffChangeRequest::STATUS_DRAFT,
                StaffChangeRequest::STATUS_SUBMITTED,
                StaffChangeRequest::STATUS_UNDER_TU_REVIEW,
                StaffChangeRequest::STATUS_REVISION_REQUESTED,
            ], 'Pengajuan yang sudah diverifikasi atau diputuskan tidak dapat dibatalkan.');

            $id = $lockedRequest->id;
            $beforeStatus = $lockedRequest->status;
            $lockedRequest->delete();

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => 'cancelled_staff_change_request',
                'model_type' => StaffChangeRequest::class,
                'model_id' => $id,
                'before_payload' => ['status' => $beforeStatus],
                'after_payload' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Verifikasi pengajuan oleh Kaur TU / Verifikator TU -> lanjut ke tahap persetujuan Kepala Madrasah.
     */
    public function verifyByTu(StaffChangeRequest $changeRequest, User $actor, ?string $notes = null): StaffChangeRequest
    {
        $this->ensureTuReviewerAuthority($actor);

        return DB::transaction(function () use ($changeRequest, $actor, $notes): StaffChangeRequest {
            $lockedRequest = StaffChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureNotSelfDecision($lockedRequest, $actor);
            $this->ensureStatusIn($lockedRequest, [
                StaffChangeRequest::STATUS_SUBMITTED,
                StaffChangeRequest::STATUS_UNDER_TU_REVIEW,
            ], 'Pengajuan tidak berada pada tahap verifikasi Tata Usaha.');

            $beforeStatus = $lockedRequest->status;

            StaffChangeApproval::create([
                'request_id' => $lockedRequest->id,
                'stage' => 'tu_review',
                'actor_user_id' => $actor->id,
                'decision' => StaffChangeRequest::STATUS_VERIFIED,
                'notes' => $notes,
                'decided_at' => now(),
            ]);

            $lockedRequest->update([
                'status' => StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL,
                'current_stage' => 'head_approval',
            ]);

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => 'verified_staff_change_request',
                'model_type' => StaffChangeRequest::class,
                'model_id' => $lockedRequest->id,
                'before_payload' => ['status' => $beforeStatus],
                'after_payload' => [
                    'status' => $lockedRequest->status,
                    'current_stage' => $lockedRequest->current_stage,
                    'notes' => $notes,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $headApprovers = $this->resolveStageRecipients('head_approval', $actor->id);
            if ($headApprovers->isNotEmpty()) {
                DB::afterCommit(function () use ($headApprovers, $lockedRequest): void {
                    Notification::send($headApprovers, new ChangeRequestSubmitted($lockedRequest));
                });
            }

            return $lockedRequest->fresh(['items', 'attachments', 'approvals.actor', 'histories']);
        });
    }

    /**
     * Minta revisi kepada pemohon (dari tahap TU maupun Kepala Madrasah).
     */
    public function requestRevision(StaffChangeRequest $changeRequest, User $actor, string $notes): StaffChangeRequest
    {
        return DB::transaction(function () use ($changeRequest, $actor, $notes): StaffChangeRequest {
            $lockedRequest = StaffChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureNotSelfDecision($lockedRequest, $actor);
            $this->ensureStatusIn($lockedRequest, [
                StaffChangeRequest::STATUS_SUBMITTED,
                StaffChangeRequest::STATUS_UNDER_TU_REVIEW,
                StaffChangeRequest::STATUS_VERIFIED,
                StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL,
            ], 'Pengajuan pada status ini tidak dapat diminta revisi.');

            $isHeadStage = in_array($lockedRequest->status, [
                StaffChangeRequest::STATUS_VERIFIED,
                StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL,
            ], true);

            if ($isHeadStage) {
                $this->ensureHeadApproverAuthority($actor);
            } else {
                $this->ensureTuReviewerAuthority($actor);
            }

            $beforeStatus = $lockedRequest->status;
            $stage = $isHeadStage ? 'head_approval' : 'tu_review';

            StaffChangeApproval::create([
                'request_id' => $lockedRequest->id,
                'stage' => $stage,
                'actor_user_id' => $actor->id,
                'decision' => StaffChangeRequest::STATUS_REVISION_REQUESTED,
                'notes' => $notes,
                'decided_at' => now(),
            ]);

            $lockedRequest->update([
                'status' => StaffChangeRequest::STATUS_REVISION_REQUESTED,
                'current_stage' => 'draft',
            ]);

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => 'revision_requested_staff_change_request',
                'model_type' => StaffChangeRequest::class,
                'model_id' => $lockedRequest->id,
                'before_payload' => ['status' => $beforeStatus],
                'after_payload' => [
                    'status' => $lockedRequest->status,
                    'notes' => $notes,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $requester = $lockedRequest->requester;
            if ($requester) {
                DB::afterCommit(function () use ($requester, $lockedRequest, $notes): void {
                    $requester->notify(new RevisionRequested($lockedRequest, $notes));
                });
            }

            return $lockedRequest->fresh(['items', 'attachments', 'approvals.actor', 'histories']);
        });
    }

    /**
     * Tolak pengajuan pada tahap verifikasi Tata Usaha.
     */
    public function rejectByTu(StaffChangeRequest $changeRequest, User $actor, string $notes): StaffChangeRequest
    {
        $this->ensureTuReviewerAuthority($actor);

        return $this->recordRejection(
            changeRequest: $changeRequest,
            actor: $actor,
            stage: 'tu_review',
            notes: $notes,
            allowedStatuses: [
                StaffChangeRequest::STATUS_SUBMITTED,
                StaffChangeRequest::STATUS_UNDER_TU_REVIEW,
            ],
            invalidStatusMessage: 'Pengajuan tidak berada pada tahap verifikasi Tata Usaha.',
        );
    }

    /**
     * Setujui pengajuan pada tahap Kepala Madrasah dan terapkan perubahan secara atomik.
     */
    public function approveByHead(
        StaffChangeRequest $changeRequest,
        User $actor,
        ?string $notes = null,
        bool $autoApply = true,
    ): StaffChangeRequest {
        $this->ensureHeadApproverAuthority($actor);

        $approvedRequest = DB::transaction(function () use ($changeRequest, $actor, $notes): StaffChangeRequest {
            $lockedRequest = StaffChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureNotSelfDecision($lockedRequest, $actor);
            $this->ensureStatusIn($lockedRequest, [
                StaffChangeRequest::STATUS_VERIFIED,
                StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL,
            ], 'Pengajuan belum diverifikasi Tata Usaha atau tidak berada pada tahap persetujuan Kepala Madrasah.');

            $beforeStatus = $lockedRequest->status;

            StaffChangeApproval::create([
                'request_id' => $lockedRequest->id,
                'stage' => 'head_approval',
                'actor_user_id' => $actor->id,
                'decision' => StaffChangeRequest::STATUS_APPROVED,
                'notes' => $notes,
                'decided_at' => now(),
            ]);

            $lockedRequest->update([
                'status' => StaffChangeRequest::STATUS_APPROVED,
                'current_stage' => 'head_approval',
                'decided_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => 'approved_by_head_staff_change_request',
                'model_type' => StaffChangeRequest::class,
                'model_id' => $lockedRequest->id,
                'before_payload' => ['status' => $beforeStatus],
                'after_payload' => [
                    'status' => $lockedRequest->status,
                    'notes' => $notes,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $requester = $lockedRequest->requester;
            if ($requester) {
                DB::afterCommit(function () use ($requester, $lockedRequest, $notes): void {
                    $requester->notify(new ChangeRequestApproved($lockedRequest, $notes));
                });
            }

            return $lockedRequest->fresh(['items', 'attachments', 'approvals.actor', 'histories']);
        });

        if ($autoApply) {
            return $this->apply($approvedRequest, $actor);
        }

        return $approvedRequest;
    }

    /**
     * Tolak pengajuan pada tahap persetujuan Kepala Madrasah.
     */
    public function rejectByHead(StaffChangeRequest $changeRequest, User $actor, string $notes): StaffChangeRequest
    {
        $this->ensureHeadApproverAuthority($actor);

        return $this->recordRejection(
            changeRequest: $changeRequest,
            actor: $actor,
            stage: 'head_approval',
            notes: $notes,
            allowedStatuses: [
                StaffChangeRequest::STATUS_VERIFIED,
                StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL,
            ],
            invalidStatusMessage: 'Pengajuan tidak berada pada tahap persetujuan Kepala Madrasah.',
        );
    }

    /**
     * Terapkan pengajuan yang sudah disetujui ke tabel master `staff` secara idempoten dengan `lockForUpdate`.
     * Field lokal diterapkan langsung; field otoritas EMIS ditandai `pending_external_sync` tanpa merusak data lokal.
     */
    public function apply(StaffChangeRequest $changeRequest, ?User $actor = null): StaffChangeRequest
    {
        if ($changeRequest->status === StaffChangeRequest::STATUS_COMPLETED) {
            return $changeRequest->load(['items', 'attachments', 'approvals.actor', 'histories']);
        }

        $this->ensureStatusIn($changeRequest, [
            StaffChangeRequest::STATUS_APPROVED,
            StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC,
            StaffChangeRequest::STATUS_FAILED,
        ], 'Hanya pengajuan yang telah disetujui Kepala Madrasah yang dapat diterapkan ke data induk.');

        return DB::transaction(function () use ($changeRequest, $actor): StaffChangeRequest {
            $lockedRequest = StaffChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status === StaffChangeRequest::STATUS_COMPLETED) {
                return $lockedRequest->load(['items', 'attachments', 'approvals.actor', 'histories']);
            }

            $lockedStaff = Staff::query()
                ->whereKey($lockedRequest->staff_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest->update(['status' => StaffChangeRequest::STATUS_APPLYING]);

            $beforePayload = [];
            $afterPayload = [];
            $hasExternalPending = false;
            $actorId = $actor?->id ?? auth()->id();

            foreach ($lockedRequest->items()->get() as $item) {
                if ($item->apply_status === 'applied') {
                    continue;
                }

                $policy = config("data_ownership.staff.{$item->field}");
                $ownerSystem = $policy['owner_system'] ?? $item->owner_system ?? 'local';
                $changePolicy = $policy['change_policy'] ?? $item->change_policy ?? 'direct';

                if ($ownerSystem === 'emis' || $changePolicy === 'read_only') {
                    $item->update([
                        'owner_system' => $ownerSystem,
                        'change_policy' => $changePolicy,
                        'apply_status' => 'pending_external_sync',
                        'apply_error' => 'Field berstatus otoritas EMIS Kemenag; menunggu sinkronisasi eksternal.',
                    ]);
                    $hasExternalPending = true;

                    continue;
                }

                $beforePayload[$item->field] = $lockedStaff->getAttribute($item->field);
                $lockedStaff->setAttribute($item->field, $item->proposed_value);
                $afterPayload[$item->field] = $item->proposed_value;

                $item->update([
                    'owner_system' => $ownerSystem,
                    'change_policy' => $changePolicy,
                    'apply_status' => 'applied',
                    'apply_error' => null,
                ]);

                StaffChangeHistory::create([
                    'request_id' => $lockedRequest->id,
                    'item_id' => $item->id,
                    'applied_value' => $item->proposed_value === null ? null : (string) $item->proposed_value,
                    'applied_by' => $actorId,
                    'applied_at' => now(),
                    'source' => 'local',
                ]);
            }

            if (! empty($afterPayload)) {
                $lockedStaff->save();

                ActivityLog::create([
                    'user_id' => $actorId,
                    'action' => 'applied_staff_change_request',
                    'model_type' => Staff::class,
                    'model_id' => $lockedStaff->id,
                    'before_payload' => $beforePayload,
                    'after_payload' => $afterPayload,
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);

                $eventPayload = [
                    'id' => $lockedStaff->id,
                    'change_request_id' => $lockedRequest->id,
                    'nip' => $lockedStaff->nip,
                    'nuptk' => $lockedStaff->nuptk,
                    'full_name_with_titles' => $lockedStaff->full_name_with_titles,
                    'employment_status' => $lockedStaff->employment_status,
                    'is_active' => $lockedStaff->is_active,
                    'updated_fields' => array_keys($afterPayload),
                ];

                app(OutboxService::class)->record(
                    aggregateType: Staff::class,
                    aggregateId: $lockedStaff->id,
                    eventType: 'staff.updated',
                    payload: $eventPayload,
                );
            }

            $finalStatus = $hasExternalPending
                ? StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC
                : StaffChangeRequest::STATUS_COMPLETED;

            $lockedRequest->update([
                'status' => $finalStatus,
                'current_stage' => 'completed',
            ]);

            if ($lockedRequest->requester) {
                $requester = $lockedRequest->requester;
                DB::afterCommit(function () use ($requester, $lockedRequest): void {
                    $requester->notify(new ChangeRequestApplied($lockedRequest));
                });
            }

            return $lockedRequest->fresh(['items', 'attachments', 'approvals.actor', 'histories']);
        });
    }

    /**
     * @param  array<int, string>  $allowedStatuses
     */
    private function recordRejection(
        StaffChangeRequest $changeRequest,
        User $actor,
        string $stage,
        string $notes,
        array $allowedStatuses,
        string $invalidStatusMessage,
    ): StaffChangeRequest {
        return DB::transaction(function () use ($changeRequest, $actor, $stage, $notes, $allowedStatuses, $invalidStatusMessage): StaffChangeRequest {
            $lockedRequest = StaffChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureNotSelfDecision($lockedRequest, $actor);
            $this->ensureStatusIn($lockedRequest, $allowedStatuses, $invalidStatusMessage);

            $beforeStatus = $lockedRequest->status;

            StaffChangeApproval::create([
                'request_id' => $lockedRequest->id,
                'stage' => $stage,
                'actor_user_id' => $actor->id,
                'decision' => StaffChangeRequest::STATUS_REJECTED,
                'notes' => $notes,
                'decided_at' => now(),
            ]);

            $lockedRequest->update([
                'status' => StaffChangeRequest::STATUS_REJECTED,
                'current_stage' => $stage,
                'decided_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => $stage === 'head_approval'
                    ? 'rejected_by_head_staff_change_request'
                    : 'rejected_by_tu_staff_change_request',
                'model_type' => StaffChangeRequest::class,
                'model_id' => $lockedRequest->id,
                'before_payload' => ['status' => $beforeStatus],
                'after_payload' => [
                    'status' => $lockedRequest->status,
                    'stage' => $stage,
                    'notes' => $notes,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            if ($lockedRequest->requester) {
                $requester = $lockedRequest->requester;
                DB::afterCommit(function () use ($requester, $lockedRequest, $stage, $notes): void {
                    $requester->notify(new ChangeRequestRejected($lockedRequest, $stage, $notes));
                });
            }

            return $lockedRequest->fresh(['items', 'attachments', 'approvals.actor', 'histories']);
        });
    }

    /**
     * Rekonsiliasi item pengajuan berstatus pending_external_sync saat sinkronisasi EMIS terjadi.
     */
    public function reconcileExternalSync(
        StaffChangeRequest $changeRequest,
        ?User $actor = null,
        int $timeoutDays = 14,
    ): StaffChangeRequest {
        $this->ensureStatusIn($changeRequest, [
            StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC,
        ], 'Hanya pengajuan berstatus pending_external_sync yang dapat direkonsiliasi.');

        return DB::transaction(function () use ($changeRequest, $actor, $timeoutDays): StaffChangeRequest {
            $lockedRequest = StaffChangeRequest::whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();
            $staff = $lockedRequest->staff()->lockForUpdate()->firstOrFail();
            $actorId = $actor?->id ?? auth()->id();

            $pendingItems = $lockedRequest->items()
                ->where('apply_status', 'pending_external_sync')
                ->get();

            $referenceTime = $lockedRequest->decided_at ?? $lockedRequest->updated_at ?? $lockedRequest->created_at;
            $isTimedOut = $timeoutDays > 0 && $referenceTime && $referenceTime->lte(now()->subDays($timeoutDays));

            foreach ($pendingItems as $item) {
                $currentVal = $staff->getAttribute($item->field);
                $currentNormalized = ($currentVal === null || $currentVal === '') ? null : (string) $currentVal;
                $proposedNormalized = ($item->proposed_value === null || $item->proposed_value === '') ? null : (string) $item->proposed_value;
                $oldNormalized = ($item->old_value === null || $item->old_value === '') ? null : (string) $item->old_value;

                if ($currentNormalized === $proposedNormalized) {
                    $item->update([
                        'apply_status' => 'applied',
                        'apply_error' => null,
                    ]);

                    StaffChangeHistory::create([
                        'request_id' => $lockedRequest->id,
                        'item_id' => $item->id,
                        'applied_value' => $proposedNormalized,
                        'applied_by' => $actorId,
                        'applied_at' => now(),
                        'source' => 'emis_sync',
                    ]);

                    continue;
                }

                if ($currentNormalized !== $oldNormalized) {
                    $item->update([
                        'apply_status' => 'failed',
                        'apply_error' => sprintf(
                            'Konflik sinkronisasi EMIS: nilai eksternal (%s) berbeda dengan nilai pengajuan (%s).',
                            $currentNormalized ?? 'NULL',
                            $proposedNormalized ?? 'NULL',
                        ),
                    ]);

                    continue;
                }

                if ($isTimedOut) {
                    $item->update([
                        'apply_status' => 'failed',
                        'apply_error' => sprintf(
                            'Batas waktu sinkronisasi EMIS (%d hari) telah terlampaui tanpa perubahan dari sistem eksternal.',
                            $timeoutDays,
                        ),
                    ]);
                }
            }

            $stillPendingCount = $lockedRequest->items()
                ->where('apply_status', 'pending_external_sync')
                ->count();

            $failedCount = $lockedRequest->items()
                ->where('apply_status', 'failed')
                ->count();

            if ($stillPendingCount === 0) {
                if ($failedCount > 0) {
                    $lockedRequest->update([
                        'status' => StaffChangeRequest::STATUS_FAILED,
                        'current_stage' => 'failed',
                    ]);
                } else {
                    $lockedRequest->update([
                        'status' => StaffChangeRequest::STATUS_COMPLETED,
                        'current_stage' => 'completed',
                    ]);

                    if ($lockedRequest->requester) {
                        $lockedRequest->requester->notify(new ChangeRequestApplied($lockedRequest));
                    }
                }
            }

            return $lockedRequest->fresh(['items', 'histories']);
        });
    }

    /**
     * @param  array<string, mixed>  $proposedChanges
     * @return array<int, array<string, mixed>>
     */
    private function buildNormalizedItems(Staff $staff, array $proposedChanges): array
    {
        $dictionary = $this->dataOwnershipService->getEntityDictionary('staff');
        $items = [];

        foreach ($proposedChanges as $field => $proposedValue) {
            if (! array_key_exists($field, $dictionary)) {
                continue;
            }

            $currentValue = $staff->getAttribute($field);
            $currentString = ($currentValue === null || $currentValue === '') ? null : (string) $currentValue;
            $proposedString = ($proposedValue === null || $proposedValue === '') ? null : (string) $proposedValue;

            if ($currentString === $proposedString) {
                continue;
            }

            $meta = $dictionary[$field];
            $items[] = [
                'field' => $field,
                'old_value' => $currentString,
                'proposed_value' => $proposedString,
                'owner_system' => $meta['owner_system'] ?? 'local',
                'change_policy' => $meta['change_policy'] ?? 'direct',
                'apply_status' => 'pending',
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    private function storeAttachments(StaffChangeRequest $changeRequest, array $attachments, string $uploadedBy): void
    {
        $storedPaths = [];

        try {
            foreach ($attachments as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $attachmentId = (string) Str::uuid();
                $ext = $file->getClientOriginalExtension() ?: 'bin';
                $relativePath = "archives/staff_change_attachment/{$attachmentId}.{$ext}";
                $contents = file_get_contents($file->getRealPath());

                Storage::disk('local')->put($relativePath, $contents);
                $storedPaths[] = $relativePath;

                StaffChangeAttachment::create([
                    'id' => $attachmentId,
                    'request_id' => $changeRequest->id,
                    'path' => $relativePath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'checksum' => hash('sha256', $contents ?: ''),
                    'uploaded_by' => $uploadedBy,
                ]);
            }
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }
    }

    private function ensureNotSelfDecision(StaffChangeRequest $changeRequest, User $actor): void
    {
        if ($changeRequest->requester_user_id === $actor->id || ($changeRequest->created_by && $changeRequest->created_by === $actor->id)) {
            throw ValidationException::withMessages([
                'decision' => 'Pemohon tidak diizinkan memverifikasi atau menyetujui pengajuannya sendiri.',
            ]);
        }

        if ($changeRequest->staff_id && $actor->staff_id && $changeRequest->staff_id === $actor->staff_id) {
            throw ValidationException::withMessages([
                'decision' => 'Pengguna yang terhubung dengan data GTK yang sama dilarang memverifikasi atau menyetujui pengajuan ini.',
            ]);
        }
    }

    public function authorizeTuReviewer(?User $actor): void
    {
        if (! $actor) {
            abort(401);
        }

        try {
            $this->ensureTuReviewerAuthority($actor);
        } catch (ValidationException $exception) {
            abort(403, $exception->getMessage());
        }
    }

    public function authorizeHeadApprover(?User $actor): void
    {
        if (! $actor) {
            abort(401);
        }

        try {
            $this->ensureHeadApproverAuthority($actor);
        } catch (ValidationException $exception) {
            abort(403, $exception->getMessage());
        }
    }

    private function ensureTuReviewerAuthority(User $actor): void
    {
        if ($actor->hasRole('admin') && ! $actor->hasRole('kaur_tu')) {
            throw ValidationException::withMessages([
                'actor' => 'Admin teknis tidak berwenang memverifikasi pengajuan perubahan data GTK.',
            ]);
        }

        if (! $actor->hasRole('kaur_tu') || ! $actor->can('verify_staff_changes')) {
            throw ValidationException::withMessages([
                'actor' => 'Hanya Kaur TU yang memiliki kewenangan memverifikasi perubahan data GTK.',
            ]);
        }
    }

    private function ensureHeadApproverAuthority(User $actor): void
    {
        if ($actor->hasRole('admin') && ! $actor->hasRole('kepala_madrasah')) {
            throw ValidationException::withMessages([
                'actor' => 'Admin teknis tidak berwenang menyetujui pengajuan perubahan data GTK.',
            ]);
        }

        if (! $actor->hasRole('kepala_madrasah') || ! $actor->can('approve_staff_changes')) {
            throw ValidationException::withMessages([
                'actor' => 'Hanya Kepala Madrasah yang memiliki kewenangan menyetujui perubahan data GTK.',
            ]);
        }
    }

    /**
     * @param  array<int, string>  $allowedStatuses
     */
    private function ensureStatusIn(StaffChangeRequest $changeRequest, array $allowedStatuses, string $message): void
    {
        if (! in_array($changeRequest->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => $message,
            ]);
        }
    }

    /**
     * Resolusi penerima notifikasi berdasarkan struktur organisasi (WP3.4) dengan fallback role.
     *
     * @return Collection<int, User>
     */
    private function resolveStageRecipients(string $stage, string $excludeUserId)
    {
        $level = $stage === 'head_approval' ? 1 : 2;
        $approverStaff = $this->organizationService->resolveApproverByLevel($level);

        if ($approverStaff && $approverStaff->user && $approverStaff->user->id !== $excludeUserId) {
            return collect([$approverStaff->user]);
        }

        $roles = $stage === 'head_approval'
            ? ['kepala_madrasah']
            : ['kaur_tu'];

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->where('is_active', true)
            ->whereKeyNot($excludeUserId)
            ->get();
    }
}
