<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\User;
use App\Services\DataOwnershipService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffMasterCrudTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_staff_show_page_renders_with_profile_ownership_and_documents(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'Drs. H. Mulyadi, M.Pd.',
            'nip' => '197001011995031001',
            'phone' => '08123456789',
        ]);

        $this->actingAs($admin)
            ->get("/gtk/{$staff->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gtk/Show', false)
                ->where('staff.id', $staff->id)
                ->where('staff.full_name_with_titles', 'Drs. H. Mulyadi, M.Pd.')
                ->has('staff.documents')
                ->has('ownership')
            );
    }

    public function test_staff_edit_page_renders_with_editable_and_readonly_fields(): void
    {
        $staf = User::factory()->create(['role' => 'staf']);
        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'Siti Nurhaliza, S.Pd.',
        ]);

        $this->actingAs($staf)
            ->get("/gtk/{$staff->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gtk/Edit', false)
                ->where('staff.id', $staff->id)
                ->has('editableFields')
                ->has('readOnlyFields')
            );
    }

    public function test_staff_update_allows_direct_administrative_fields_blocks_approval_required_fields_and_records_audit_and_outbox(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = Staff::factory()->create([
            'nik' => '3201010101010001',
            'nip' => '197501012000031001',
            'full_name_with_titles' => 'Nama Lama',
            'phone' => '0811111111',
            'employment_status' => 'GTT',
            'is_active' => true,
        ]);

        // 1. Percobaan bypass: mengirim HANYA field approval_required tanpa field direct -> wajib ditolak!
        $this->actingAs($admin)
            ->from("/gtk/{$staff->id}/edit")
            ->put("/gtk/{$staff->id}", [
                'full_name_with_titles' => 'Nama Baru, S.Pd.',
                'phone' => '0899999999',
                'employment_status' => 'PNS',
                'nik' => '9999999999999999',
                'nip' => '8888888888888888',
            ])
            ->assertRedirect("/gtk/{$staff->id}/edit")
            ->assertSessionHasErrors('approval_required');

        $staff->refresh();
        $this->assertSame('Nama Lama', $staff->full_name_with_titles);
        $this->assertSame('0811111111', $staff->phone);
        $this->assertSame('GTT', $staff->employment_status);
        $this->assertSame('3201010101010001', $staff->nik);
        $this->assertSame('197501012000031001', $staff->nip);
        $this->assertTrue($staff->is_active);

        // 2. Mengubah field direct (is_active) disertai field approval_required:
        // Hanya is_active yang diterapkan, sedangkan field approval_required & read_only diabaikan.
        $this->actingAs($admin)
            ->put("/gtk/{$staff->id}", [
                'is_active' => false,
                'full_name_with_titles' => 'Nama Bypass',
                'phone' => '0899999999',
                'nik' => '9999999999999999',
            ])
            ->assertRedirect("/gtk/{$staff->id}")
            ->assertSessionHas('success');

        $staff->refresh();

        $this->assertFalse($staff->is_active);
        $this->assertSame('Nama Lama', $staff->full_name_with_titles);
        $this->assertSame('0811111111', $staff->phone);
        $this->assertSame('3201010101010001', $staff->nik);

        // Verifikasi audit log tercatat di activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'updated',
            'model_type' => Staff::class,
            'model_id' => $staff->id,
            'user_id' => $admin->id,
        ]);

        // Verifikasi transactional outbox event staff.updated tercatat tanpa PII sensitif (NIK)
        $outbox = OutboxEvent::query()
            ->where('aggregate_type', Staff::class)
            ->where('aggregate_id', $staff->id)
            ->where('event_type', 'staff.updated')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame('pending', $outbox->status);
        $this->assertArrayNotHasKey('nik', $outbox->payload);
        $this->assertContains('is_active', $outbox->payload['changed_fields'] ?? []);
    }

    public function test_staff_document_can_be_uploaded_and_deleted_in_private_storage(): void
    {
        Storage::fake('local');

        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $staff = Staff::factory()->create();

        $file = UploadedFile::fake()->create('sk_pns.pdf', 120, 'application/pdf');

        $this->actingAs($kaurTu)
            ->post("/gtk/{$staff->id}/documents", [
                'document_type' => 'sk_pengangkatan',
                'title' => 'SK Pengangkatan PNS 2024',
                'file' => $file,
            ])
            ->assertRedirect("/gtk/{$staff->id}")
            ->assertSessionHas('success');

        $document = StaffDocument::query()->where('staff_id', $staff->id)->first();
        $this->assertNotNull($document);
        $this->assertSame('SK Pengangkatan PNS 2024', $document->title);
        $this->assertSame('sk_pengangkatan', $document->document_type);
        Storage::disk('local')->assertExists($document->file_path);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'uploaded_document',
            'model_type' => Staff::class,
            'model_id' => $staff->id,
            'user_id' => $kaurTu->id,
        ]);

        $filePath = $document->file_path;

        $this->actingAs($kaurTu)
            ->delete("/gtk/{$staff->id}/documents/{$document->id}")
            ->assertRedirect("/gtk/{$staff->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('staff_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($filePath);
    }

    public function test_kepala_madrasah_can_view_staff_profile_but_cannot_edit_or_upload_document(): void
    {
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $staff = Staff::factory()->create();

        $this->actingAs($kamad)
            ->get('/gtk')
            ->assertOk();

        $this->actingAs($kamad)
            ->get("/gtk/{$staff->id}")
            ->assertOk();

        $this->actingAs($kamad)
            ->get("/gtk/{$staff->id}/edit")
            ->assertForbidden();

        $this->actingAs($kamad)
            ->put("/gtk/{$staff->id}", [
                'full_name_with_titles' => 'Tidak Boleh Edit',
                'employment_status' => 'PNS',
            ])
            ->assertForbidden();
    }

    public function test_data_ownership_service_identifies_editable_and_readonly_fields(): void
    {
        $service = new DataOwnershipService;

        $this->assertTrue($service->isEditable('staff', 'is_active'));
        $this->assertFalse($service->isEditable('staff', 'phone'));
        $this->assertFalse($service->isEditable('staff', 'full_name_with_titles'));

        $this->assertFalse($service->isEditable('staff', 'nik'));
        $this->assertFalse($service->isEditable('staff', 'nip'));
        $this->assertFalse($service->isEditable('staff', 'tpg_status'));
    }

    public function test_guru_cannot_edit_staff(): void
    {
        $guru = User::factory()->create(['role' => 'guru']);
        $staff = Staff::factory()->create();

        $this->actingAs($guru)
            ->get("/gtk/{$staff->id}/edit")
            ->assertForbidden();

        $this->actingAs($guru)
            ->put("/gtk/{$staff->id}", [
                'full_name_with_titles' => 'Hacker Name',
            ])
            ->assertForbidden();
    }
}
