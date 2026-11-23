<?php

namespace App\Notifications;

use App\Models\StaffChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChangeRequestApproved extends Notification
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
            'event' => 'change_request.approved',
            'request_id' => $this->changeRequest->id,
            'staff_id' => $this->changeRequest->staff_id,
            'status' => $this->changeRequest->status,
            'notes' => $this->notes,
            'title' => 'Pengajuan Perubahan Data Disetujui',
            'message' => 'Pengajuan perubahan data GTK Anda telah disetujui Kepala Madrasah.',
        ];
    }
}
