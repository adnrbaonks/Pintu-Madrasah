<?php

namespace App\Notifications;

use App\Models\StaffChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RevisionRequested extends Notification
{
    use Queueable;

    public function __construct(
        public StaffChangeRequest $changeRequest,
        public ?string $notes = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'change_request.revision_requested',
            'request_id' => $this->changeRequest->id,
            'staff_id' => $this->changeRequest->staff_id,
            'status' => $this->changeRequest->status,
            'notes' => $this->notes,
            'title' => 'Pengajuan Perubahan Data Perlu Revisi',
            'message' => $this->notes ?: 'Mohon perbaiki data atau berkas pendukung pengajuan Anda.',
        ];
    }
}
