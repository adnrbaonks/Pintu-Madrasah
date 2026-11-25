<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\ArchiveCategory;
use App\Models\PkkmComponent;
use App\Models\PkkmEvidence;
use App\Models\PkkmInstrument;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PkkmManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unassigned_user_cannot_read_or_change_pkkm_instrument(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $outsider = User::factory()->create(['role' => 'guru']);
        $instrument = PkkmInstrument::create([
            'code' => 'PKKM/2026/999',
            'title' => 'PKKM privat',
            'period_year' => '2026',
            'assessment_type' => 'annual',
            'headmaster_user_id' => $admin->id,
            'status' => PkkmInstrument::STATUS_DRAFT,
            'overall_score' => 0,
            'created_by' => $admin->id,
        ]);
        $component = PkkmComponent::create([
            'pkkm_instrument_id' => $instrument->id,
            'category_code' => 'A',
            'category_name' => 'A',
            'code' => 'A1',
            'title' => 'Komponen',
            'weight' => 1,
            'max_score' => 100,
            'sort_order' => 1,
            'pic_user_id' => $admin->id,
        ]);

        $this->actingAs($outsider)->get("/pkkm/{$instrument->id}")->assertForbidden();
        $this->actingAs($outsider)->post("/pkkm/components/{$component->id}/assess", [
            'self_score' => 100,
        ])->assertForbidden();
        $this->actingAs($outsider)->post("/pkkm/components/{$component->id}/evidence", [
            'title' => 'Bukti tanpa izin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('pkkm_assessments', ['pkkm_component_id' => $component->id]);
        $this->assertDatabaseMissing('pkkm_evidence', ['pkkm_component_id' => $component->id]);
    }

    public function test_pkkm_lifecycle_dynamic_components_evidence_and_scoring(): void
    {
        Storage::fake('local');

        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $guru = User::factory()->create(['role' => 'guru']);

        // 1. Buat instrumen PKKM periode 2026 dengan baseline 5 unsur default
        $this->actingAs($kaurTu)->post('/pkkm', [
            'title' => 'PKKM Tahunan 2026 MTs Terpadu',
            'period_year' => '2026',
            'assessment_type' => 'annual',
            'headmaster_user_id' => $kamad->id,
            'seed_default_components' => true,
        ])->assertRedirect();

        $instrument = PkkmInstrument::firstOrFail();
        $this->assertSame(5, $instrument->components()->count());
        $this->assertSame(PkkmInstrument::STATUS_DRAFT, $instrument->status);

        // 2. Tambah komponen indikator baru secara dinamis (tidak di-hardcode)
        $this->actingAs($kaurTu)->post("/pkkm/{$instrument->id}/components", [
            'category_code' => 'INNOVATION',
            'category_name' => 'Inovasi Digital',
            'code' => 'DIG-1',
            'title' => 'Penerapan Administrasi Madrasah Bebas Kertas (Paperless)',
            'indicator_description' => 'Implementasi modul arsip digital dan tata persuratan elektronik.',
            'weight' => 2.0,
            'max_score' => 100,
        ])->assertRedirect();

        $this->assertSame(6, $instrument->fresh()->components()->count());
        $newComponent = PkkmComponent::where('code', 'DIG-1')->firstOrFail();

        // 3. Simpan evaluasi diri dan skor verifikasi asesor pada komponen
        $this->actingAs($kamad)->post("/pkkm/components/{$newComponent->id}/assess", [
            'self_score' => 90,
            'verified_score' => 95,
            'self_notes' => 'Sistem PINTU MADRASAH aktif digunakan.',
            'assessor_notes' => 'Bukti digital terverifikasi sangat baik.',
        ])->assertRedirect();

        $instrument->refresh();
        $this->assertGreaterThan(0, $instrument->overall_score);
        $this->assertNotNull($instrument->predicate);

        // 4. Kaitkan bukti fisik ke Arsip Digital
        app(ArchiveService::class)->ensureDefaultCategories();
        $archiveCategory = ArchiveCategory::firstOrFail();
        $archive = Archive::create([
            'archive_category_id' => $archiveCategory->id,
            'archive_number' => 'ARS/TEST/2026/001',
            'title' => 'Pedoman Paperless Madrasah 2026',
            'visibility' => Archive::VISIBILITY_INTERNAL,
            'created_by' => $kaurTu->id,
        ]);

        $evidenceFile = UploadedFile::fake()->create('panduan-paperless.pdf', 150, 'application/pdf');

        $this->actingAs($kaurTu)->post("/pkkm/components/{$newComponent->id}/evidence", [
            'title' => 'SK & Panduan Sistem Paperless',
            'archive_id' => $archive->id,
            'file' => $evidenceFile,
            'notes' => 'Terkait modul Arsip Digital WP8',
        ])->assertRedirect();

        $this->assertSame(1, $newComponent->fresh()->evidence()->count());
        $evidence = $newComponent->fresh()->evidence()->first();
        $this->assertSame($archive->id, $evidence->archive_id);
        $this->assertSame('pending', $evidence->verification_status);

        // Pengunggah tidak boleh melakukan self-verification.
        $this->actingAs($kaurTu)->post("/pkkm/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])->assertForbidden();

        // Asesor lain dapat memverifikasi bukti.
        $this->actingAs($kamad)->post("/pkkm/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])->assertRedirect();
        $this->assertSame('verified', $evidence->fresh()->verification_status);

        // 5. Guru biasa tidak dapat memfinalisasi instrumen PKKM (403)
        $this->actingAs($guru)->post("/pkkm/{$instrument->id}/finalize")->assertForbidden();

        // 6. Kepala Madrasah memfinalisasi instrumen PKKM
        $this->actingAs($kamad)->post("/pkkm/{$instrument->id}/finalize")->assertRedirect();

        $instrument->refresh();
        $this->assertSame(PkkmInstrument::STATUS_FINALIZED, $instrument->status);
        $this->assertSame($kamad->id, $instrument->finalized_by);
        $this->assertNotNull($instrument->finalized_at);
    }

    public function test_pkkm_security_pic_and_uploader_cannot_self_verify(): void
    {
        Storage::fake('local');

        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $picGuru = User::factory()->create(['role' => 'guru']);
        $guruLain = User::factory()->create(['role' => 'guru']);

        $instrument = PkkmInstrument::create([
            'code' => 'PKKM/2026/SEC',
            'title' => 'PKKM Security Test',
            'period_year' => '2026',
            'assessment_type' => 'annual',
            'headmaster_user_id' => $kamad->id,
            'status' => PkkmInstrument::STATUS_IN_PROGRESS,
            'overall_score' => 0,
            'created_by' => $kaurTu->id,
        ]);

        $component = PkkmComponent::create([
            'pkkm_instrument_id' => $instrument->id,
            'category_code' => 'A',
            'category_name' => 'Usaha Madrasah',
            'code' => 'A1',
            'title' => 'Komponen A1',
            'weight' => 20,
            'max_score' => 100,
            'sort_order' => 1,
            'pic_user_id' => $picGuru->id,
        ]);

        // 0. PIC komponen berhak melihat halaman detail instrumen, sedangkan guru lain ditolak (403)
        $this->actingAs($picGuru)->get("/pkkm/{$instrument->id}")->assertOk();
        $this->actingAs($guruLain)->get("/pkkm/{$instrument->id}")->assertForbidden();

        // 1. PIC komponen dilarang mengisi verified_score (hanya boleh self_score)
        $this->actingAs($picGuru)->post("/pkkm/components/{$component->id}/assess", [
            'self_score' => 85,
            'verified_score' => 90,
        ])->assertForbidden();

        // 2. Evaluator sah (Kaur TU) dapat mengisi verified_score dan assessor_notes
        $this->actingAs($kaurTu)->post("/pkkm/components/{$component->id}/assess", [
            'verified_score' => 90,
            'assessor_notes' => 'Telah diverifikasi sesuai bukti fisik',
        ])->assertRedirect();

        $this->assertDatabaseHas('pkkm_assessments', [
            'pkkm_component_id' => $component->id,
            'verified_score' => 90,
            'assessor_notes' => 'Telah diverifikasi sesuai bukti fisik',
        ]);

        // 3. Upload bukti oleh PIC
        $file = UploadedFile::fake()->create('sop.pdf', 500, 'application/pdf');
        $this->actingAs($picGuru)->post("/pkkm/components/{$component->id}/evidence", [
            'title' => 'Dokumen SOP Madrasah',
            'file' => $file,
        ])->assertRedirect();

        $evidence = $component->evidence()->firstOrFail();

        // 4. PIC dilarang mereview/memverifikasi bukti sendiri (403)
        $this->actingAs($picGuru)->post("/pkkm/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])->assertForbidden();

        // 5. Verifikasi oleh Kepala Madrasah / Asesor diizinkan
        $this->actingAs($kamad)->post("/pkkm/evidence/{$evidence->id}/review", [
            'verification_status' => 'verified',
        ])->assertRedirect();

        $this->assertSame('verified', $evidence->fresh()->verification_status);
    }

    public function test_pkkm_show_filters_private_archive_metadata_and_blocks_linking(): void
    {
        app(ArchiveService::class)->ensureDefaultCategories();
        $category = ArchiveCategory::firstOrFail();

        $owner = User::factory()->create(['role' => 'admin']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $picGuru = User::factory()->create(['role' => 'guru']);

        $private = Archive::create([
            'archive_category_id' => $category->id,
            'archive_number' => 'ARS/PKKM/PRIVATE',
            'title' => 'Arsip privat pemilik lain',
            'visibility' => Archive::VISIBILITY_PRIVATE,
            'created_by' => $owner->id,
        ]);

        $instrument = PkkmInstrument::create([
            'code' => 'PKKM/2026/ARCHIVE',
            'title' => 'PKKM dengan arsip privat',
            'period_year' => '2026',
            'assessment_type' => 'annual',
            'headmaster_user_id' => $kamad->id,
            'status' => PkkmInstrument::STATUS_IN_PROGRESS,
            'overall_score' => 0,
            'created_by' => $kamad->id,
        ]);

        $component = PkkmComponent::create([
            'pkkm_instrument_id' => $instrument->id,
            'category_code' => 'A',
            'category_name' => 'A',
            'code' => 'A1',
            'title' => 'Komponen',
            'weight' => 1,
            'max_score' => 100,
            'sort_order' => 1,
            'pic_user_id' => $picGuru->id,
        ]);

        PkkmEvidence::create([
            'pkkm_component_id' => $component->id,
            'archive_id' => $private->id,
            'title' => 'Bukti privat',
            'verification_status' => 'pending',
            'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($picGuru)
            ->get("/pkkm/{$instrument->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pkkm/Show', false)
                ->where('archives', [])
                ->where('instrument.components.0.evidence.0.archive', null)
            );

        $this->actingAs($picGuru)->post("/pkkm/components/{$component->id}/evidence", [
            'title' => 'Bukti dengan arsip terlarang',
            'archive_id' => $private->id,
        ])->assertForbidden();
    }

    public function test_pkkm_index_enforces_permission_or_assignment_scoping(): void
    {
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $picGuru = User::factory()->create(['role' => 'guru']);
        $otherGuru = User::factory()->create(['role' => 'guru']);

        $assignedInstrument = PkkmInstrument::create([
            'code' => 'PKKM/2026/ASSIGNED',
            'title' => 'PKKM Ditugaskan ke PIC Guru',
            'period_year' => '2026',
            'assessment_type' => 'annual',
            'headmaster_user_id' => $kamad->id,
            'status' => PkkmInstrument::STATUS_IN_PROGRESS,
            'overall_score' => 0,
            'created_by' => $kamad->id,
        ]);

        PkkmComponent::create([
            'pkkm_instrument_id' => $assignedInstrument->id,
            'category_code' => 'A',
            'category_name' => 'A',
            'code' => 'A1',
            'title' => 'Komponen PIC',
            'weight' => 1,
            'max_score' => 100,
            'sort_order' => 1,
            'pic_user_id' => $picGuru->id,
        ]);

        $unassignedInstrument = PkkmInstrument::create([
            'code' => 'PKKM/2026/OTHER',
            'title' => 'PKKM Tanpa PIC Guru',
            'period_year' => '2026',
            'assessment_type' => 'annual',
            'headmaster_user_id' => $kamad->id,
            'status' => PkkmInstrument::STATUS_DRAFT,
            'overall_score' => 0,
            'created_by' => $kamad->id,
        ]);

        // Guru tanpa permission dan tanpa penugasan ditolak (403) pada index maupun show
        $this->actingAs($otherGuru)->get('/pkkm')->assertForbidden();
        $this->actingAs($otherGuru)->get("/pkkm/{$assignedInstrument->id}")->assertForbidden();

        // Guru yang menjadi PIC komponen boleh membuka index tetapi hanya melihat instrumen miliknya
        $this->actingAs($picGuru)
            ->get('/pkkm')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pkkm/Index', false)
                ->has('instruments', 1)
                ->where('instruments.0.id', $assignedInstrument->id)
            );

        // Kaur TU dengan permission view_pkkm melihat seluruh instrumen
        $this->actingAs($kaurTu)
            ->get('/pkkm')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pkkm/Index', false)
                ->has('instruments', 2)
            );
    }
}
