<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\ArchiveCategory;
use App\Models\PkkmComponent;
use App\Models\PkkmInstrument;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PkkmManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

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

        // 5. Guru biasa tidak dapat memfinalisasi instrumen PKKM (403)
        $this->actingAs($guru)->post("/pkkm/{$instrument->id}/finalize")->assertForbidden();

        // 6. Kepala Madrasah memfinalisasi instrumen PKKM
        $this->actingAs($kamad)->post("/pkkm/{$instrument->id}/finalize")->assertRedirect();

        $instrument->refresh();
        $this->assertSame(PkkmInstrument::STATUS_FINALIZED, $instrument->status);
        $this->assertSame($kamad->id, $instrument->finalized_by);
        $this->assertNotNull($instrument->finalized_at);
    }
}
