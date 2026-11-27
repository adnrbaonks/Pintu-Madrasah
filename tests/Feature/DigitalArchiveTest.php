<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\ArchiveAccessLog;
use App\Models\ArchiveCategory;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
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

    public function test_archive_detail_hides_access_logs_from_users_without_view_audit_logs_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $guru = User::factory()->create(['role' => 'guru']);

        app(ArchiveService::class)->ensureDefaultCategories();
        $category = ArchiveCategory::where('code', 'TATA-USAHA')->firstOrFail();

        $archive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/TU/2026/888',
            'title' => 'Pengumuman Internal',
            'visibility' => Archive::VISIBILITY_INTERNAL,
            'created_by' => $admin->id,
        ]);

        // Guru melihat arsip -> mencatat access log tetapi payload access_logs harus kosong
        $this->actingAs($guru)
            ->get("/arsip-digital/{$archive->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Archives/Show', false)
                ->where('canViewAuditLogs', false)
                ->where('archive.access_logs', [])
            );

        // Kepala Madrasah memiliki view_audit_logs -> melihat riwayat akses terstruktur
        $this->actingAs($kamad)
            ->get("/arsip-digital/{$archive->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Archives/Show', false)
                ->where('canViewAuditLogs', true)
                ->has('archive.access_logs', 2)
                ->has('archive.access_logs.0.ip_address')
            );
    }

    public function test_archive_owner_without_manage_archives_can_edit_content_but_cannot_escalate_visibility_or_classification(): void
    {
        $guru = User::factory()->create(['role' => 'guru']);
        $staf = User::factory()->create(['role' => 'staf']);

        app(ArchiveService::class)->ensureDefaultCategories();
        $categoryA = ArchiveCategory::where('code', 'TATA-USAHA')->firstOrFail();
        $categoryB = ArchiveCategory::where('code', 'KURIKULUM')->firstOrFail();

        $archive = Archive::create([
            'archive_category_id' => $categoryA->id,
            'archive_number' => 'ARS/TU/2026/777',
            'title' => 'Arsip Pribadi Guru',
            'description' => 'Catatan awal',
            'classification_code' => 'KP.01',
            'visibility' => Archive::VISIBILITY_PRIVATE,
            'status' => Archive::STATUS_ACTIVE,
            'created_by' => $guru->id,
            'owner_user_id' => $guru->id,
        ]);

        // Pemilik tanpa manage_archives boleh mengubah judul/deskripsi arsipnya sendiri
        $this->actingAs($guru)->put("/arsip-digital/{$archive->id}", [
            'archive_category_id' => $categoryA->id,
            'title' => 'Arsip Pribadi Guru (Revisi)',
            'description' => 'Catatan diperbarui',
            'classification_code' => 'KP.01',
            'visibility' => Archive::VISIBILITY_PRIVATE,
            'status' => Archive::STATUS_ACTIVE,
        ])->assertRedirect();

        $this->assertSame('Arsip Pribadi Guru (Revisi)', $archive->fresh()->title);

        // Pemilik tanpa manage_archives TIDAK boleh menaikkan visibilitas menjadi internal atau mengubah klasifikasi
        $this->actingAs($guru)->put("/arsip-digital/{$archive->id}", [
            'archive_category_id' => $categoryB->id,
            'title' => 'Arsip Pribadi Guru (Internal)',
            'description' => 'Upaya eskalasi',
            'classification_code' => 'OT.00',
            'visibility' => Archive::VISIBILITY_INTERNAL,
            'status' => Archive::STATUS_ACTIVE,
        ])->assertForbidden();

        $this->assertSame(Archive::VISIBILITY_PRIVATE, $archive->fresh()->visibility);
        $this->assertSame('KP.01', $archive->fresh()->classification_code);

        // Jika permission manage_archives dicabut dari role staf, staf bukan pemilik tidak lagi dapat mengelola arsip
        Role::findByName('staf')->revokePermissionTo('manage_archives');
        $this->assertFalse(app(ArchiveService::class)->canManage($staf->fresh(), $archive));
    }

    public function test_archive_upload_cleans_up_stored_file_when_database_transaction_fails(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $category = ArchiveCategory::create([
            'code' => 'KP.99',
            'name' => 'Kepegawaian Uji Transaksi',
            'retention_years' => 5,
            'default_visibility' => 'internal',
            'is_active' => true,
        ]);

        $service = app(ArchiveService::class);
        $archive = $service->createArchive(
            [
                'archive_category_id' => $category->id,
                'title' => 'Arsip Versi 1 Valid',
                'classification_code' => 'KP.99',
                'visibility' => Archive::VISIBILITY_INTERNAL,
            ],
            UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf'),
            $admin
        );

        $v1Path = $archive->latestVersion->file_path;
        Storage::disk('local')->assertExists($v1Path);
        $filesBefore = Storage::disk('local')->allFiles('archives/digital');

        // Paksa kegagalan di dalam transaksi saat membuat ArchiveAccessLog pada uploadNewVersion
        ArchiveAccessLog::creating(function () {
            throw new \RuntimeException('Simulated DB failure during version upload');
        });

        try {
            $service->uploadNewVersion(
                $archive->fresh(),
                UploadedFile::fake()->create('v2-failed.pdf', 120, 'application/pdf'),
                $admin,
                'Versi 2 gagal DB'
            );
            $this->fail('Upload versi baru seharusnya melempar exception saat transaksi DB gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated DB failure during version upload', $e->getMessage());
        } finally {
            ArchiveAccessLog::flushEventListeners();
        }

        // File versi 1 tetap aman, sedangkan file versi 2 yang batal harus sudah dibersihkan dari disk
        Storage::disk('local')->assertExists($v1Path);
        $filesAfter = Storage::disk('local')->allFiles('archives/digital');
        $this->assertSame($filesBefore, $filesAfter);
        $this->assertSame(1, $archive->fresh()->versions()->count());
    }

    public function test_archive_index_filters_viewable_records_at_sql_level_before_limit_preventing_starvation(): void
    {
        $ownerOther = User::factory()->create(['role' => 'guru']);
        $guruViewer = User::factory()->create(['role' => 'guru']);

        $category = ArchiveCategory::create([
            'code' => 'OT.77',
            'name' => 'Kategori Uji Starvation',
            'retention_years' => 5,
            'default_visibility' => 'internal',
            'is_active' => true,
        ]);

        // Buat 3 arsip lebih lama yang boleh dilihat guruViewer (1 miliknya, 1 internal, 1 role_restricted guru)
        $ownArchive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/VIEW/OWN',
            'title' => 'Arsip Pribadi Guru Viewer',
            'visibility' => Archive::VISIBILITY_PRIVATE,
            'created_by' => $guruViewer->id,
            'owner_user_id' => $guruViewer->id,
            'created_at' => now()->subHours(5),
        ]);

        $internalArchive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/VIEW/INTERNAL',
            'title' => 'Arsip Internal Madrasah',
            'visibility' => Archive::VISIBILITY_INTERNAL,
            'created_by' => $ownerOther->id,
            'created_at' => now()->subHours(4),
        ]);

        $roleArchive = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/VIEW/ROLE',
            'title' => 'Arsip Khusus Role Guru',
            'visibility' => Archive::VISIBILITY_ROLE_RESTRICTED,
            'allowed_roles' => ['guru'],
            'created_by' => $ownerOther->id,
            'created_at' => now()->subHours(3),
        ]);

        // Buat 210 arsip terbaru yang TIDAK boleh dilihat guruViewer (melampaui limit 200 di ArchiveController::index)
        $unviewableRows = [];
        $now = now();
        for ($i = 1; $i <= 210; $i++) {
            $unviewableRows[] = [
                'id' => (string) Str::uuid(),
                'archive_category_id' => $category->id,
                'archive_number' => sprintf('ARS/PRIV/NOISE/%03d', $i),
                'title' => "Arsip Privat Orang Lain {$i}",
                'visibility' => Archive::VISIBILITY_PRIVATE,
                'created_by' => $ownerOther->id,
                'owner_user_id' => $ownerOther->id,
                'status' => Archive::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Archive::insert($unviewableRows);

        $this->actingAs($guruViewer)
            ->get('/arsip-digital')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Archives/Index', false)
                ->has('archives', 3)
                ->where('archives', function ($items) use ($ownArchive, $internalArchive, $roleArchive): bool {
                    $ids = collect($items)->pluck('id')->all();

                    return in_array($ownArchive->id, $ids, true)
                        && in_array($internalArchive->id, $ids, true)
                        && in_array($roleArchive->id, $ids, true);
                })
            );
    }
}
