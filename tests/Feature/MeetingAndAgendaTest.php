<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Archive;
use App\Models\ArchiveCategory;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingMinute;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia;
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

    public function test_meeting_security_constraints_and_assignee_rules(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $guruPeserta = User::factory()->create(['role' => 'guru']);
        $guruLuar = User::factory()->create(['role' => 'guru']);

        $meeting = Meeting::create([
            'meeting_number' => 'NOTULEN/2026/001',
            'title' => 'Rapat Terbatas Manajemen',
            'meeting_type' => 'dinas',
            'scheduled_at' => now()->addDay(),
            'leader_user_id' => $kamad->id,
            'notetaker_user_id' => $admin->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
        $meeting->participants()->create([
            'user_id' => $guruPeserta->id,
            'attendance_status' => 'registered',
        ]);

        // 1. Assign action ke guru yang BUKAN peserta rapat harus ditolak (422)
        $this->actingAs($kamad)->post("/rapat-dinas/{$meeting->id}/actions", [
            'assigned_to_user_id' => $guruLuar->id,
            'title' => 'Tugas untuk guru luar',
            'due_date' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasErrors(['assigned_to_user_id']);

        // 2. Action yang ditugaskan ke guruPeserta tidak boleh diselesaikan oleh guruLuar (403)
        $action = MeetingAction::create([
            'meeting_id' => $meeting->id,
            'assigned_to_user_id' => $guruPeserta->id,
            'title' => 'Tugas internal peserta',
            'status' => MeetingAction::STATUS_PENDING,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($guruLuar)->post("/rapat-dinas/actions/{$action->id}", [
            'status' => MeetingAction::STATUS_COMPLETED,
            'resolution_notes' => 'Mencoba menyelesaikan tugas orang lain',
        ])->assertForbidden();

        // 3. Guru luar rapat tidak melihat rapat terbatas di daftar rapat
        $response = $this->actingAs($guruLuar)->get('/rapat-dinas');
        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Meetings/Index', false)
            ->where('meetings', fn ($data) => collect($data)->where('id', $meeting->id)->isEmpty())
        );

        // 4. Guru peserta DAPAT melihat rapat di daftar rapat
        $responsePeserta = $this->actingAs($guruPeserta)->get('/rapat-dinas');
        $responsePeserta->assertOk();
        $responsePeserta->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Meetings/Index', false)
            ->where('meetings', fn ($data) => collect($data)->where('id', $meeting->id)->isNotEmpty())
        );

        // 5. Guru luar rapat ditolak (403) ketika mengakses detail rapat secara langsung (show)
        $this->actingAs($guruLuar)->get("/rapat-dinas/{$meeting->id}")->assertForbidden();
        $this->actingAs($guruPeserta)->get("/rapat-dinas/{$meeting->id}")->assertOk();
    }

    public function test_meeting_show_filters_private_archive_metadata_and_blocks_linking_unviewable_archives(): void
    {
        $owner = User::factory()->create(['role' => 'guru']);
        $notetaker = User::factory()->create(['role' => 'guru']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        app(ArchiveService::class)->ensureDefaultCategories();
        $category = ArchiveCategory::firstOrFail();

        $internalArchive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/MTG/INTERNAL',
            'title' => 'Arsip Internal Rapat',
            'visibility' => Archive::VISIBILITY_INTERNAL,
            'created_by' => $owner->id,
            'created_at' => now()->subHour(),
        ]);

        // Buat > 50 arsip privat terbaru milik owner lain untuk memastikan recentViewable tidak kehabisan kuota
        for ($i = 1; $i <= 55; $i++) {
            $privateArchive = Archive::create([
                'archive_category_id' => $category->id,
                'archive_number' => sprintf('ARS/MTG/PRIV/%03d', $i),
                'title' => "Arsip Privat {$i}",
                'visibility' => Archive::VISIBILITY_PRIVATE,
                'created_by' => $owner->id,
                'owner_user_id' => $owner->id,
            ]);
        }

        $meeting = Meeting::create([
            'meeting_number' => 'RPT/SEC/2026/002',
            'title' => 'Rapat Uji Filter Arsip',
            'meeting_type' => 'koordinasi',
            'scheduled_at' => now()->addDay(),
            'leader_user_id' => $kamad->id,
            'notetaker_user_id' => $notetaker->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        // Notulen sudah tertaut ke arsip privat milik owner lain
        MeetingMinute::create([
            'meeting_id' => $meeting->id,
            'discussion_summary' => 'Ringkasan awal',
            'decisions' => 'Keputusan awal',
            'archive_id' => $privateArchive->id,
            'recorded_by' => $owner->id,
        ]);

        // Notetaker tidak boleh melihat arsip privat di dropdown archives maupun relasi minutes.archive,
        // tetapi arsip internal yang lebih lama tetap muncul walaupun ada 55 arsip privat yang lebih baru.
        $this->actingAs($notetaker)
            ->get("/rapat-dinas/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Meetings/Show', false)
                ->where('meeting.minutes.archive', null)
                ->where('archives', fn ($items) => collect($items)->pluck('id')->contains($internalArchive->id)
                    && ! collect($items)->pluck('id')->contains($privateArchive->id))
            );

        // Notetaker ditolak (403) jika mencoba menautkan arsip privat milik orang lain pada notulen
        $this->actingAs($notetaker)
            ->post("/rapat-dinas/{$meeting->id}/minutes", [
                'discussion_summary' => 'Ringkasan diperbarui',
                'decisions' => 'Keputusan diperbarui',
                'archive_id' => $privateArchive->id,
            ])
            ->assertForbidden();
    }

    public function test_approved_meeting_minutes_are_locked_from_direct_overwrite_and_require_explicit_revision_audit(): void
    {
        $notetaker = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        $meeting = Meeting::create([
            'meeting_number' => 'RPT/LOCK/2026/001',
            'title' => 'Rapat Uji Kunci Notulen',
            'meeting_type' => 'dinas',
            'scheduled_at' => now()->addDay(),
            'leader_user_id' => $kamad->id,
            'notetaker_user_id' => $notetaker->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        // 1. Notulis menyimpan notulen awal
        $this->actingAs($notetaker)
            ->post("/rapat-dinas/{$meeting->id}/minutes", [
                'discussion_summary' => 'Ringkasan versi 1 sebelum pengesahan.',
                'decisions' => 'Keputusan versi 1 sebelum pengesahan.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // 2. Kepala Madrasah mengesahkan notulen
        $this->actingAs($kamad)
            ->post("/rapat-dinas/{$meeting->id}/minutes/approve")
            ->assertRedirect();

        $minute = $meeting->minutes()->firstOrFail();
        $this->assertNotNull($minute->approved_at);
        $this->assertSame($kamad->id, $minute->approved_by);
        $this->assertSame(Meeting::STATUS_COMPLETED, $meeting->fresh()->status);

        // 3. Upaya menimpa langsung notulen yang telah disahkan tanpa revision_notes ditolak
        $this->actingAs($notetaker)
            ->post("/rapat-dinas/{$meeting->id}/minutes", [
                'discussion_summary' => 'Upaya menimpa diam-diam.',
                'decisions' => 'Keputusan diubah diam-diam.',
            ])
            ->assertSessionHasErrors('minutes');

        $minute->refresh();
        $this->assertSame('Ringkasan versi 1 sebelum pengesahan.', $minute->discussion_summary);
        $this->assertSame('Keputusan versi 1 sebelum pengesahan.', $minute->decisions);
        $this->assertNotNull($minute->approved_at);

        // 4. Revisi eksplisit mencatat snapshot versi sebelumnya, alasan perubahan, dan mereset status pengesahan
        $this->actingAs($notetaker)
            ->post("/rapat-dinas/{$meeting->id}/minutes/revise", [
                'discussion_summary' => 'Ringkasan versi 2 pasca revisi.',
                'decisions' => 'Keputusan versi 2 pasca revisi.',
                'revision_notes' => 'Koreksi redaksi keputusan anggaran sesuai lampiran RKAM.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $minute->refresh();
        $this->assertSame('Ringkasan versi 2 pasca revisi.', $minute->discussion_summary);
        $this->assertSame('Keputusan versi 2 pasca revisi.', $minute->decisions);
        $this->assertNull($minute->approved_at);
        $this->assertNull($minute->approved_by);
        $this->assertSame(Meeting::STATUS_ONGOING, $meeting->fresh()->status);

        $revision = $minute->revisions()->firstOrFail();
        $this->assertSame(1, $revision->version_number);
        $this->assertSame('Ringkasan versi 1 sebelum pengesahan.', $revision->previous_discussion_summary);
        $this->assertSame('Keputusan versi 1 sebelum pengesahan.', $revision->previous_decisions);
        $this->assertSame($kamad->id, $revision->previous_approved_by);
        $this->assertNotNull($revision->previous_approved_at);
        $this->assertSame($notetaker->id, $revision->revised_by);
        $this->assertSame('Koreksi redaksi keputusan anggaran sesuai lampiran RKAM.', $revision->revision_notes);

        // 5. Kepala Madrasah dapat mengesahkan ulang notulen hasil revisi
        $this->actingAs($kamad)
            ->post("/rapat-dinas/{$meeting->id}/minutes/approve")
            ->assertRedirect();

        $this->assertNotNull($minute->fresh()->approved_at);
        $this->assertSame(Meeting::STATUS_COMPLETED, $meeting->fresh()->status);
    }
}
