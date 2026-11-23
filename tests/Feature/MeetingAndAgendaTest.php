<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MeetingAndAgendaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unassigned_users_cannot_change_meeting_records_or_read_meeting_details(): void
    {
        $organizer = User::factory()->create(['role' => 'kaur_tu']);
        $stranger = User::factory()->create(['role' => 'guru']);
        $target = User::factory()->create(['role' => 'guru']);
        $meeting = Meeting::create([
            'meeting_number' => 'NOT/'.now()->format('Y').'/9998',
            'title' => 'Rapat terbatas',
            'meeting_type' => 'dinas',
            'scheduled_at' => now()->addDay(),
            'location' => 'Aula',
            'leader_user_id' => $organizer->id,
            'notetaker_user_id' => $organizer->id,
            'created_by' => $organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $organizer->id,
            'role_in_meeting' => 'leader',
            'attendance_status' => 'invited',
        ]);
        $action = MeetingAction::create([
            'meeting_id' => $meeting->id,
            'assigned_to_user_id' => $target->id,
            'title' => 'Tindak lanjut',
            'status' => MeetingAction::STATUS_PENDING,
        ]);

        $this->actingAs($stranger)->get("/rapat-dinas/{$meeting->id}")->assertForbidden();
        $this->actingAs($stranger)->post("/rapat-dinas/{$meeting->id}/attendance", [
            'user_id' => $target->id,
            'attendance_status' => 'present',
        ])->assertForbidden();
        $this->actingAs($stranger)->post("/rapat-dinas/{$meeting->id}/minutes", [
            'discussion_summary' => 'Tidak berwenang',
            'decisions' => 'Tidak berwenang',
        ])->assertForbidden();
        $this->actingAs($stranger)->post("/rapat-dinas/{$meeting->id}/actions", [
            'title' => 'Tidak berwenang',
        ])->assertForbidden();
        $this->actingAs($stranger)->post("/rapat-dinas/actions/{$action->id}", [
            'status' => MeetingAction::STATUS_COMPLETED,
        ])->assertForbidden();

        $this->assertDatabaseMissing('meeting_participants', [
            'meeting_id' => $meeting->id,
            'user_id' => $target->id,
            'attendance_status' => 'present',
        ]);
        $this->assertDatabaseMissing('meeting_minutes', ['meeting_id' => $meeting->id]);
        $this->assertSame(MeetingAction::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_meeting_lifecycle_minutes_actions_attendance_and_calendar_sync(): void
    {
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $guru = User::factory()->create(['role' => 'guru']);

        AcademicYear::factory()->create(['is_active' => true, 'year' => '2026/2027', 'semester' => 'Ganjil']);

        // 1. Jadwalkan rapat dinas baru (otomatis membuat CalendarEvent dan mendaftarkan peserta)
        $this->actingAs($kaurTu)->post('/rapat-dinas', [
            'title' => 'Rapat Koordinasi Evaluasi Tengah Semester',
            'meeting_type' => 'dinas',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'location' => 'Aula Serbaguna Madrasah',
            'leader_user_id' => $kamad->id,
            'notetaker_user_id' => $kaurTu->id,
            'agenda' => 'Evaluasi capaian kurikulum dan serapan anggaran.',
            'participant_ids' => [$guru->id],
        ])->assertRedirect();

        $meeting = Meeting::firstOrFail();
        $this->assertSame(Meeting::STATUS_SCHEDULED, $meeting->status);
        $this->assertSame(3, $meeting->participants()->count());

        // Pastikan sinkronisasi ke Kalender Madrasah terbentuk
        $this->assertDatabaseHas('calendar_events', [
            'meeting_id' => $meeting->id,
            'event_type' => 'meeting',
        ]);

        // 2. Presensi kehadiran peserta rapat
        $this->actingAs($kaurTu)->post("/rapat-dinas/{$meeting->id}/attendance", [
            'user_id' => $guru->id,
            'attendance_status' => 'present',
        ])->assertRedirect();

        $participant = MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('user_id', $guru->id)
            ->firstOrFail();
        $this->assertSame('present', $participant->attendance_status);
        $this->assertNotNull($participant->attended_at);

        // 3. Simpan notulen rapat
        $this->actingAs($kaurTu)->post("/rapat-dinas/{$meeting->id}/minutes", [
            'discussion_summary' => 'Pembahasan progres pembelajaran dan modul ajar.',
            'decisions' => 'Seluruh guru wajib melengkapi administrasi kelas dalam 7 hari.',
        ])->assertRedirect();

        $this->assertDatabaseHas('meeting_minutes', [
            'meeting_id' => $meeting->id,
            'recorded_by' => $kaurTu->id,
        ]);

        // 4. Tambah action item (tindak lanjut)
        $this->actingAs($kaurTu)->post("/rapat-dinas/{$meeting->id}/actions", [
            'assigned_to_user_id' => $guru->id,
            'title' => 'Pengumpulan Nilai Formatif Siswa',
            'due_date' => now()->addDays(7)->toDateString(),
        ])->assertRedirect();

        $action = MeetingAction::firstOrFail();
        $this->assertSame(MeetingAction::STATUS_PENDING, $action->status);

        // Selesaikan action item
        $this->actingAs($guru)->post("/rapat-dinas/actions/{$action->id}", [
            'status' => MeetingAction::STATUS_COMPLETED,
            'resolution_notes' => 'Nilai formatif lengkap diunggah ke rapor digital.',
        ])->assertRedirect();

        $this->assertSame(MeetingAction::STATUS_COMPLETED, $action->fresh()->status);
        $this->assertNotNull($action->fresh()->completed_at);

        // 5. Guru biasa tidak dapat mengesahkan notulen (403)
        $this->actingAs($guru)->post("/rapat-dinas/{$meeting->id}/minutes/approve")->assertForbidden();

        // 6. Kepala Madrasah mengesahkan notulen -> status rapat berubah jadi 'completed'
        $this->actingAs($kamad)->post("/rapat-dinas/{$meeting->id}/minutes/approve")->assertRedirect();

        $this->assertSame(Meeting::STATUS_COMPLETED, $meeting->fresh()->status);
        $this->assertSame($kamad->id, $meeting->minutes->fresh()->approved_by);

        // 7. Pengumuman Madrasah
        $this->actingAs($kaurTu)->post('/pengumuman', [
            'title' => 'Pengumuman Libur Hari Besar Islam',
            'content' => 'Madrasah diliburkan pada tanggal 1 Muharram 1448 H.',
            'target_scope' => 'all',
            'priority' => 'normal',
            'publish_date' => now()->toDateString(),
        ])->assertRedirect();

        $announcement = Announcement::firstOrFail();
        $this->assertTrue($announcement->is_active);

        // 8. Kalender Agenda Manual
        $this->actingAs($kaurTu)->post('/kalender', [
            'title' => 'Asesmen Akhir Semester Gasal',
            'event_type' => 'exam',
            'start_time' => now()->addDays(14)->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Asesmen Akhir Semester Gasal',
            'event_type' => 'exam',
        ]);

        // 9. Command Pengingat (ReminderCommand)
        $action2 = MeetingAction::create([
            'meeting_id' => $meeting->id,
            'assigned_to_user_id' => $guru->id,
            'title' => 'Tugas lampau yang belum selesai',
            'status' => MeetingAction::STATUS_PENDING,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('reminders:process-upcoming')->assertSuccessful();
        $this->assertSame(MeetingAction::STATUS_OVERDUE, $action2->fresh()->status);
    }
}
