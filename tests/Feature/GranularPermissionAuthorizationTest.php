<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Archive;
use App\Models\ArchiveCategory;
use App\Models\PkkmInstrument;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GranularPermissionAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_meeting_management_permission_revocation_blocks_scheduling(): void
    {
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $guru = User::factory()->create(['role' => 'guru']);

        $payload = [
            'title' => 'Rapat Evaluasi Semester',
            'meeting_type' => 'evaluasi',
            'scheduled_at' => '2026-12-01 09:00:00',
            'location' => 'Ruang Rapat',
        ];

        $this->actingAs($kaurTu)->post('/rapat-dinas', $payload)->assertRedirect();
        $this->actingAs($guru)->post('/rapat-dinas', $payload)->assertForbidden();

        $this->revoke('kaur_tu', 'manage_meetings');

        $this->actingAs($kaurTu->fresh())->post('/rapat-dinas', $payload)->assertForbidden();

        // Permission tampilan global tetap terpisah dari kewenangan menjadwalkan.
        $this->assertTrue($kaurTu->fresh()->can('view_meetings'));
    }

    public function test_program_approval_permission_is_separate_from_management(): void
    {
        $staf = User::factory()->create(['role' => 'staf']);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        $first = $this->verifiedProgram($staf, 'PRG/VERIFY/1');
        $second = $this->verifiedProgram($staf, 'PRG/APPROVE/1');

        // Staf dapat membuat program, tetapi tidak dapat menyetujuinya.
        $this->assertTrue($staf->can('create_programs'));
        $this->actingAs($staf)->post("/program-kerja/{$first->id}/status", [
            'status' => Program::STATUS_APPROVED,
        ])->assertForbidden();

        // Kaur TU memverifikasi tetapi tidak menyetujui.
        $this->actingAs($kaurTu)->post("/program-kerja/{$first->id}/status", [
            'status' => Program::STATUS_APPROVED,
        ])->assertForbidden();
        $this->assertTrue($kaurTu->can('verify_programs'));
        $this->assertFalse($kaurTu->can('approve_programs'));

        $this->actingAs($kamad)->post("/program-kerja/{$second->id}/status", [
            'status' => Program::STATUS_APPROVED,
        ])->assertRedirect();

        // Pencabutan permission persetujuan langsung membatasi Kepala Madrasah.
        $third = $this->verifiedProgram($staf, 'PRG/APPROVE/2');
        $this->revoke('kepala_madrasah', 'approve_programs');

        $this->actingAs($kamad->fresh())->post("/program-kerja/{$third->id}/status", [
            'status' => Program::STATUS_APPROVED,
        ])->assertForbidden();
    }

    public function test_pkkm_view_permission_revocation_blocks_instrument_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $operator = User::factory()->create(['role' => 'operator']);

        $instrument = PkkmInstrument::create([
            'code' => 'PKKM/TEST/1',
            'title' => 'PKKM Uji Otorisasi',
            'period_year' => '2026',
            'assessment_type' => 'annual',
            'headmaster_user_id' => $kamad->id,
            'status' => PkkmInstrument::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($operator)->get("/pkkm/{$instrument->id}")->assertOk();

        $this->revoke('operator', 'view_pkkm');

        $this->actingAs($operator->fresh())->get("/pkkm/{$instrument->id}")->assertForbidden();

        // Kepala Madrasah tetap memiliki akses melalui identitas headmaster dan permission.
        $this->actingAs($kamad)->get("/pkkm/{$instrument->id}")->assertOk();
    }

    public function test_archive_deletion_and_announcement_deletion_are_permission_gated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $staf = User::factory()->create(['role' => 'staf']);

        $category = ArchiveCategory::create([
            'code' => 'TEST',
            'name' => 'Kategori Uji',
        ]);

        $archive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/TEST/1',
            'title' => 'Arsip Uji',
            'visibility' => Archive::VISIBILITY_INTERNAL,
            'created_by' => $admin->id,
            'owner_user_id' => $admin->id,
        ]);

        $announcement = Announcement::create([
            'title' => 'Pengumuman Uji',
            'content' => 'Konten pengumuman uji.',
            'target_scope' => 'all',
            'priority' => 'normal',
            'publish_date' => now()->toDateString(),
            'author_user_id' => $admin->id,
            'is_active' => true,
        ]);

        // Staf memegang manage_announcements tetapi tidak boleh menghapus pengumuman.
        $this->assertTrue($staf->can('manage_announcements'));
        $this->actingAs($staf)->delete("/pengumuman/{$announcement->id}")->assertForbidden();

        // Staf tidak memiliki kewenangan menghapus arsip.
        $this->actingAs($staf)->delete("/arsip-digital/{$archive->id}")->assertForbidden();

        $this->actingAs($kaurTu)->delete("/pengumuman/{$announcement->id}")->assertRedirect();
        $this->assertFalse($announcement->fresh()->is_active);

        $this->actingAs($kaurTu)->delete("/arsip-digital/{$archive->id}")->assertRedirect();
        $this->assertSoftDeleted('archives', ['id' => $archive->id]);

        $this->revoke('kaur_tu', 'delete_archives', 'delete_announcements');

        $secondArchive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/TEST/2',
            'title' => 'Arsip Uji 2',
            'visibility' => Archive::VISIBILITY_INTERNAL,
            'created_by' => $admin->id,
            'owner_user_id' => $admin->id,
        ]);
        $secondAnnouncement = Announcement::create([
            'title' => 'Pengumuman Uji 2',
            'content' => 'Konten pengumuman uji kedua.',
            'target_scope' => 'all',
            'priority' => 'normal',
            'publish_date' => now()->toDateString(),
            'author_user_id' => $admin->id,
            'is_active' => true,
        ]);

        $this->actingAs($kaurTu->fresh())->delete("/arsip-digital/{$secondArchive->id}")->assertForbidden();
        $this->actingAs($kaurTu->fresh())->delete("/pengumuman/{$secondAnnouncement->id}")->assertForbidden();
    }

    private function verifiedProgram(User $creator, string $code): Program
    {
        return Program::create([
            'code' => $code,
            'title' => 'Program '.$code,
            'status' => Program::STATUS_VERIFIED,
            'progress_percent' => 0,
            'budget_amount' => 0,
            'created_by' => $creator->id,
            'pic_user_id' => $creator->id,
        ]);
    }

    private function revoke(string $role, string ...$permissions): void
    {
        Role::findByName($role)->revokePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
