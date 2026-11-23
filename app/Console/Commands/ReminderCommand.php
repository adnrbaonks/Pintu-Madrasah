<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Services\ArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReminderCommand extends Command
{
    protected $signature = 'reminders:process-upcoming';

    protected $description = 'Proses pengingat rapat dinas, jatuh tempo tindak lanjut, dan evaluasi masa retensi arsip digital.';

    public function handle(ArchiveService $archiveService): int
    {
        $this->info('Memulai pemrosesan pengingat administrasi madrasah...');

        // 1. Evaluasi masa retensi arsip digital
        $expiredArchives = $archiveService->markExpiredArchives();
        $this->info("Arsip melewati batas retensi: {$expiredArchives} dokumen ditandai expired.");

        // 2. Evaluasi rapat mendatang dalam 24 jam ke depan
        $upcomingMeetings = Meeting::query()
            ->where('status', Meeting::STATUS_SCHEDULED)
            ->whereBetween('scheduled_at', [now(), now()->addDay()])
            ->count();
        $this->info("Rapat mendatang dalam 24 jam: {$upcomingMeetings} jadwal.");

        // 3. Evaluasi tindak lanjut (action items) yang jatuh tempo atau terlewat
        $overdueCount = MeetingAction::query()
            ->whereIn('status', [MeetingAction::STATUS_PENDING, MeetingAction::STATUS_IN_PROGRESS])
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => MeetingAction::STATUS_OVERDUE]);

        $this->info("Tindak lanjut melewati batas waktu: {$overdueCount} ditandai overdue.");

        Log::info('reminders:process-upcoming selesai', [
            'expired_archives' => $expiredArchives,
            'upcoming_meetings' => $upcomingMeetings,
            'overdue_actions' => $overdueCount,
        ]);

        return self::SUCCESS;
    }
}
