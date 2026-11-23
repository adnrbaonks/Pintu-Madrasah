<?php

namespace App\Notifications;

use App\Models\StaffChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChangeRequestApplied extends Notification
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
            'event' => 'change_request.applied',
            'request_id' => $this->changeRequest->id,
            'staff_id' => $this->changeRequest->staff_id,
            'status' => $this->changeRequest->status,
            'title' => 'Perubahan Data GTK Telah Diterapkan',
            'message' => $this->changeRequest->status === StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC
                ? 'Perubahan data lokal telah diterapkan; perubahan field EMIS menunggu sinkronisasi eksternal.'
                : 'Seluruh perubahan data GTK telah diterapkan ke data induk madrasah.',
        ];
    }
}
