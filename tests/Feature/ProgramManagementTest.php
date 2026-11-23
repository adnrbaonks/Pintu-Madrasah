<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramActivity;
use App\Models\ProgramReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
}
