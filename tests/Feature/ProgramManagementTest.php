<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\ArchiveCategory;
use App\Models\Program;
use App\Models\ProgramActivity;
use App\Models\ProgramEvidence;
use App\Models\ProgramReport;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgramManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_program_lifecycle_activity_reporting_evidence_and_tiered_review(): void
    {
        Storage::fake('local');

        $staf = User::factory()->create(['role' => 'staf']);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        // 1. Staf membuat program kerja baru
        $this->actingAs($staf)->post('/program-kerja', [
            'title' => 'Program Peningkatan Kompetensi Guru 2026',
            'description' => 'Pelatihan kurikulum dan asesmen madrasah',
            'budget_amount' => 15000000,
            'start_date' => '2026-01-15',
            'end_date' => '2026-06-30',
        ])->assertRedirect();

        $program = Program::firstOrFail();
        $this->assertSame(Program::STATUS_DRAFT, $program->status);

        // 2. Pengajuan -> Verifikasi TU -> Persetujuan Kepala Madrasah
        $this->actingAs($staf)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_SUBMITTED,
        ])->assertRedirect();

        // Staf tidak dapat melompati tahap verifikasi.
        $this->actingAs($staf)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_APPROVED,
        ])->assertStatus(422);

        $this->actingAs($kaurTu)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_VERIFIED,
        ])->assertRedirect();

        $this->actingAs($kamad)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_APPROVED,
            'approval_notes' => 'Disetujui untuk dilaksanakan semester ini.',
        ])->assertRedirect();

        $this->assertSame(Program::STATUS_APPROVED, $program->fresh()->status);
        $this->assertSame($kamad->id, $program->fresh()->approved_by);

        // 3. Tambah 2 kegiatan pada program kerja
        $this->actingAs($staf)->post("/program-kerja/{$program->id}/activities", [
            'title' => 'Workshop Kurikulum Madrasah',
            'target_indicator' => '100% guru hadir',
            'budget_allocation' => 8000000,
        ])->assertRedirect();

        $this->actingAs($staf)->post("/program-kerja/{$program->id}/activities", [
            'title' => 'Pendampingan Penyusunan Modul Ajar',
            'target_indicator' => 'Seluruh mapel memiliki modul ajar',
            'budget_allocation' => 7000000,
        ])->assertRedirect();

        $firstActivity = ProgramActivity::where('program_id', $program->id)->firstOrFail();

        // 4. Kirim laporan realisasi kegiatan pertama (capaian 100%) beserta bukti dokumen
        $evidenceFile = UploadedFile::fake()->create('daftar-hadir-workshop.pdf', 100, 'application/pdf');
        $reportResponse = $this->actingAs($staf)->post("/program-kerja/{$program->id}/reports", [
            'program_activity_id' => $firstActivity->id,
            'title' => 'Laporan Pelaksanaan Workshop Kurikulum',
            'report_date' => '2026-02-20',
            'summary' => 'Workshop terlaksana dengan 35 peserta guru.',
            'outcomes' => 'Draft kurikulum operasional madrasah.',
            'realized_budget' => 7800000,
            'achieved_percent' => 100,
            'evidence_title' => 'Daftar Hadir & Berita Acara Workshop',
            'evidence_file' => $evidenceFile,
        ]);
        $reportResponse->assertSessionHasNoErrors()->assertRedirect();

        // Progres program otomatis dihitung rata-rata dari 2 kegiatan (100% dan 0% = 50%)
        $this->assertSame(50, $program->fresh()->progress_percent);
        $this->assertSame('completed', $firstActivity->fresh()->status);

        $report = ProgramReport::firstOrFail();
        $this->assertSame(1, $report->evidence()->count());

        // 5. Verifikasi TU & Persetujuan Kamad atas laporan
        $this->actingAs($kaurTu)->post("/program-kerja/reports/{$report->id}/review", [
            'action' => 'verify',
            'review_notes' => 'Bukti daftar hadir lengkap.',
        ])->assertRedirect();

        $this->assertSame(ProgramReport::STATUS_VERIFIED, $report->fresh()->status);

        $this->actingAs($kamad)->post("/program-kerja/reports/{$report->id}/review", [
            'action' => 'approve',
            'review_notes' => 'Laporan diterima dan disahkan.',
        ])->assertRedirect();

        $this->assertSame(ProgramReport::STATUS_APPROVED, $report->fresh()->status);
    }

    public function test_reports_reject_unrelated_users_and_cross_program_activities(): void
    {
        $pic = User::factory()->create(['role' => 'staf']);
        $outsider = User::factory()->create(['role' => 'staf']);
        $program = Program::create([
            'code' => 'PRG/SEC/1', 'title' => 'Program privat',
            'pic_user_id' => $pic->id, 'status' => Program::STATUS_APPROVED,
        ]);
        $otherProgram = Program::create(['code' => 'PRG/SEC/2', 'title' => 'Program lain']);
        $activity = ProgramActivity::create(['program_id' => $otherProgram->id, 'title' => 'Kegiatan lain']);
        $payload = [
            'title' => 'Laporan', 'report_date' => '2026-02-20',
            'summary' => 'Ringkasan', 'achieved_percent' => 50,
            'program_activity_id' => $activity->id,
        ];

        $this->actingAs($outsider)->get("/program-kerja/{$program->id}")->assertForbidden();
        $this->actingAs($outsider)->postJson("/program-kerja/{$program->id}/reports", $payload)->assertForbidden();
        $this->actingAs($outsider)->postJson("/program-kerja/{$program->id}/status", ['status' => 'in_progress'])->assertForbidden();
        $this->actingAs($pic)->postJson("/program-kerja/{$program->id}/reports", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('program_activity_id');
        $this->assertSame(0, $program->reports()->count());
        $this->assertSame(0, $activity->fresh()->progress_percent);
    }

    public function test_program_status_cannot_skip_required_approval_stages(): void
    {
        $pic = User::factory()->create(['role' => 'staf']);
        $program = Program::create([
            'code' => 'PRG/2026/9991',
            'title' => 'Program uji transisi',
            'pic_user_id' => $pic->id,
            'created_by' => $pic->id,
            'status' => Program::STATUS_DRAFT,
            'budget_amount' => 0,
            'progress_percent' => 0,
        ]);

        $this->actingAs($pic)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_APPROVED,
        ])->assertStatus(422);

        $this->assertSame(Program::STATUS_DRAFT, $program->fresh()->status);
    }

    public function test_report_review_must_follow_stage_and_cannot_be_self_reviewed(): void
    {
        $pic = User::factory()->create(['role' => 'staf']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $program = Program::create([
            'code' => 'PRG/2026/9992',
            'title' => 'Program laporan uji',
            'pic_user_id' => $pic->id,
            'created_by' => $pic->id,
            'status' => Program::STATUS_APPROVED,
            'budget_amount' => 0,
            'progress_percent' => 0,
        ]);
        $report = ProgramReport::create([
            'program_id' => $program->id,
            'title' => 'Laporan uji',
            'report_date' => now()->toDateString(),
            'summary' => 'Ringkasan',
            'achieved_percent' => 50,
            'status' => ProgramReport::STATUS_SUBMITTED,
            'submitted_by' => $pic->id,
        ]);

        $this->actingAs($pic)->post("/program-kerja/reports/{$report->id}/review", [
            'action' => 'verify',
        ])->assertForbidden();

        $this->actingAs($kamad)->post("/program-kerja/reports/{$report->id}/review", [
            'action' => 'approve',
        ])->assertStatus(422);

        $this->assertSame(ProgramReport::STATUS_SUBMITTED, $report->fresh()->status);
    }

    public function test_program_completion_requires_all_activities_completed_100_percent_progress_and_approved_reports(): void
    {
        $pic = User::factory()->create(['role' => 'staf']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $guru = User::factory()->create(['role' => 'guru']);

        $program = Program::create([
            'code' => 'PRG/2026/COMPL-1',
            'title' => 'Program uji penyelesaian',
            'pic_user_id' => $pic->id,
            'created_by' => $pic->id,
            'status' => Program::STATUS_IN_PROGRESS,
            'budget_amount' => 1000000,
            'progress_percent' => 0,
        ]);

        // 1. Gagal diselesaikan jika belum ada kegiatan
        $this->actingAs($kamad)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_COMPLETED,
        ])->assertStatus(422);

        // Tambah kegiatan dengan status 'in_progress' dan belum selesai
        $activity = ProgramActivity::create([
            'program_id' => $program->id,
            'title' => 'Kegiatan Utama',
            'status' => 'in_progress',
            'progress_percent' => 50,
        ]);

        // 2. Gagal diselesaikan jika kegiatan belum 'completed'
        $this->actingAs($kamad)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_COMPLETED,
        ])->assertStatus(422);

        $activity->update(['status' => 'completed', 'progress_percent' => 100]);

        // 3. Gagal diselesaikan jika belum ada laporan disetujui
        $this->actingAs($kamad)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_COMPLETED,
        ])->assertStatus(422);

        // Buat laporan tetapi masih tahap submitted
        $report = ProgramReport::create([
            'program_id' => $program->id,
            'program_activity_id' => $activity->id,
            'title' => 'Laporan Akhir',
            'report_date' => now()->toDateString(),
            'summary' => 'Kegiatan selesai.',
            'achieved_percent' => 100,
            'status' => ProgramReport::STATUS_SUBMITTED,
            'submitted_by' => $pic->id,
        ]);

        // 4. Gagal diselesaikan jika laporan belum disetujui (masih submitted)
        $this->actingAs($kamad)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_COMPLETED,
        ])->assertStatus(422);

        // Setujui laporan dan set program progress 100%
        $report->update(['status' => ProgramReport::STATUS_APPROVED]);
        $program->update(['progress_percent' => 100]);

        // 5. User tanpa wewenang (guru luar) ditolak (403)
        $this->actingAs($guru)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_COMPLETED,
        ])->assertForbidden();

        // 6. PIC atau Kamad berhasil menyelesaikan program
        $this->actingAs($pic)->post("/program-kerja/{$program->id}/status", [
            'status' => Program::STATUS_COMPLETED,
        ])->assertRedirect();

        $this->assertSame(Program::STATUS_COMPLETED, $program->fresh()->status);

        // 7. Program yang sudah ditutup tidak dapat ditambah kegiatan baru (422)
        $this->actingAs($pic)->post("/program-kerja/{$program->id}/activities", [
            'title' => 'Kegiatan Terlambat',
        ])->assertStatus(422);
    }

    public function test_program_show_filters_private_archive_metadata_and_blocks_linking(): void
    {
        app(ArchiveService::class)->ensureDefaultCategories();
        $category = ArchiveCategory::firstOrFail();

        $owner = User::factory()->create(['role' => 'admin']);
        $pic = User::factory()->create(['role' => 'staf']);

        $private = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/PRG/PRIVATE',
            'title' => 'Arsip privat pemilik lain',
            'visibility' => Archive::VISIBILITY_PRIVATE,
            'created_by' => $owner->id,
        ]);

        $program = Program::create([
            'code' => 'PRG/ARCHIVE/1',
            'title' => 'Program dengan arsip privat',
            'pic_user_id' => $pic->id,
            'status' => Program::STATUS_APPROVED,
        ]);

        $activity = ProgramActivity::create([
            'program_id' => $program->id,
            'title' => 'Kegiatan',
            'status' => 'completed',
        ]);

        $report = ProgramReport::create([
            'program_id' => $program->id,
            'program_activity_id' => $activity->id,
            'title' => 'Laporan',
            'report_date' => now()->toDateString(),
            'summary' => 'Ringkasan',
            'achieved_percent' => 100,
            'status' => ProgramReport::STATUS_SUBMITTED,
            'submitted_by' => $pic->id,
        ]);

        ProgramEvidence::create([
            'program_report_id' => $report->id,
            'archive_id' => $private->id,
            'title' => 'Bukti privat',
            'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($pic)
            ->get("/program-kerja/{$program->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Programs/Show', false)
                ->where('archives', [])
                ->where('program.reports.0.evidence.0.archive', null)
            );

        $this->actingAs($pic)->post("/program-kerja/{$program->id}/reports", [
            'program_activity_id' => $activity->id,
            'title' => 'Laporan dengan arsip terlarang',
            'report_date' => now()->toDateString(),
            'summary' => 'Ringkasan',
            'achieved_percent' => 100,
            'evidence_title' => 'Bukti privat',
            'evidence_archive_id' => $private->id,
        ])->assertForbidden();
    }
}
