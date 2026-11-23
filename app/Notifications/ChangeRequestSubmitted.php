<?php

namespace App\Notifications;

use App\Models\StaffChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChangeRequestSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public StaffChangeRequest $changeRequest,
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
            'event' => 'change_request.submitted',
            'request_id' => $this->changeRequest->id,
            'staff_id' => $this->changeRequest->staff_id,
            'status' => $this->changeRequest->status,
            'title' => 'Pengajuan Perubahan Data GTK Baru',
            'message' => 'Pengajuan perubahan data kepegawaian menunggu verifikasi Tata Usaha.',
        ];
    }
}
