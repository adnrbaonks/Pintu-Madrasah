<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\ArchiveCategory;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DigitalArchiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_archive_creation_versioning_signed_url_access_control_and_retention(): void
    {
        Storage::fake('local');

        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $guru = User::factory()->create(['role' => 'guru']);
        $pegawaiLain = User::factory()->create(['role' => 'pegawai']);

        // 1. Buka halaman katalog arsip (otomatis membuat kategori default)
        $this->actingAs($kaurTu)
            ->get('/arsip-digital')
            ->assertOk();

        $category = ArchiveCategory::where('code', 'KEPEGAWAIAN')->firstOrFail();

        // 2. Unggah dokumen arsip privat baru (v1) dengan owner = $guru
        $fileV1 = UploadedFile::fake()->create('sk-penetapan-v1.pdf', 120, 'application/pdf');

        $response = $this->actingAs($kaurTu)->post('/arsip-digital', [
            'archive_category_id' => $category->id,
            'title' => 'SK Penetapan Guru Tetap 2026',
            'description' => 'Dokumen SK privat untuk guru terkait',
            'visibility' => Archive::VISIBILITY_PRIVATE,
            'document_date' => '2026-01-10',
            'file' => $fileV1,
        ]);

        $response->assertSessionHasNoErrors();
        $archive = Archive::firstOrFail();
        $response->assertRedirect(route('digital-archives.show', $archive->id));

        // Set pemilik dokumen ke $guru untuk menguji kontrol akses pemilik vs pegawai lain
        $archive->update(['owner_user_id' => $guru->id]);

        $this->assertSame(1, $archive->current_version);
        $this->assertDatabaseHas('archive_versions', [
            'archive_id' => $archive->id,
            'version_number' => 1,
            'original_name' => 'sk-penetapan-v1.pdf',
        ]);
        $this->assertDatabaseHas('archive_access_logs', [
            'archive_id' => $archive->id,
            'action' => 'created',
        ]);

        // 3. Unggah versi revisi baru (v2)
        $fileV2 = UploadedFile::fake()->create('sk-penetapan-v2.pdf', 150, 'application/pdf');
        $this->actingAs($kaurTu)->post("/arsip-digital/{$archive->id}/versions", [
            'file' => $fileV2,
            'change_notes' => 'Koreksi lampiran daftar nama',
        ])->assertRedirect();

        $archive->refresh();
        $this->assertSame(2, $archive->current_version);
        $this->assertSame(2, $archive->versions()->count());

        // 4. Kontrol akses: Pemilik ($guru) boleh membuka detail & mengunduh via Signed URL,
        //    tetapi $pegawaiLain ditolak (403 Forbidden) dan tercatat access_denied
        $this->actingAs($guru)
            ->get("/arsip-digital/{$archive->id}")
            ->assertOk();

        $this->actingAs($pegawaiLain)
            ->get("/arsip-digital/{$archive->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('archive_access_logs', [
            'archive_id' => $archive->id,
            'user_id' => $pegawaiLain->id,
            'action' => 'access_denied',
        ]);

        // 5. Verifikasi unduhan melalui Temporary Signed URL
        $archiveService = app(ArchiveService::class);
        $signedUrl = $archiveService->temporaryDownloadUrl($archive);

        $this->actingAs($guru)
            ->get($signedUrl)
            ->assertOk();

        $this->assertDatabaseHas('archive_access_logs', [
            'archive_id' => $archive->id,
            'user_id' => $guru->id,
            'action' => 'downloaded',
        ]);

        // Tautan tanpa signature valid wajib ditolak 403
        $this->actingAs($guru)
            ->get("/arsip-digital/{$archive->id}/download/2")
            ->assertForbidden();

        // 6. Evaluasi masa retensi: arsip yang melewati retention_until ditandai expired
        $archive->update(['retention_until' => now()->subDay()->toDateString()]);
        $expiredCount = $archiveService->markExpiredArchives();
        $this->assertSame(1, $expiredCount);
        $this->assertSame(Archive::STATUS_EXPIRED, $archive->fresh()->status);
    }

    public function test_role_restricted_archive_strictly_enforces_allowed_roles_via_spatie(): void
    {
        Storage::fake('local');

        $operator = User::factory()->create(['role' => 'operator']);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $guru = User::factory()->create(['role' => 'guru']);
        $admin = User::factory()->create(['role' => 'admin']);

        app(ArchiveService::class)->ensureDefaultCategories();
        $category = ArchiveCategory::where('code', 'TATA-USAHA')->firstOrFail();

        $archive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/TU/2026/999',
            'title' => 'Dokumen Terbatas Khusus Guru',
            'visibility' => Archive::VISIBILITY_ROLE_RESTRICTED,
            'allowed_roles' => ['guru'],
            'created_by' => $operator->id,
        ]);

        // Role yang ada di allowed_roles (guru), pembuat (operator), dan admin diizinkan
        $this->actingAs($guru)->get("/arsip-digital/{$archive->id}")->assertOk();
        $this->actingAs($operator)->get("/arsip-digital/{$archive->id}")->assertOk();
        $this->actingAs($admin)->get("/arsip-digital/{$archive->id}")->assertOk();

        // Role manajerial yang TIDAK ada di allowed_roles wajib ditolak (403)
        $this->actingAs($kaurTu)->get("/arsip-digital/{$archive->id}")->assertForbidden();
        $this->actingAs($kamad)->get("/arsip-digital/{$archive->id}")->assertForbidden();
    }
}
