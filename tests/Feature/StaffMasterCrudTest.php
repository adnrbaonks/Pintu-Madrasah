<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use App\Services\DataOwnershipService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

    public function test_staff_show_page_renders_with_profile_and_ownership(): void
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

    public function test_staff_update_allows_local_fields_and_filters_out_protected_emis_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = Staff::factory()->create([
            'nik' => '3201010101010001',
            'nip' => '197501012000031001',
            'full_name_with_titles' => 'Nama Lama',
            'phone' => '0811111111',
            'employment_status' => 'GTT',
        ]);

        // Kirim request update, termasuk mencoba manipulasi NIK & NIP (yang berstatus read-only EMIS)
        $this->actingAs($admin)
            ->put("/gtk/{$staff->id}", [
                'full_name_with_titles' => 'Nama Baru, S.Pd.',
                'phone' => '0899999999',
                'employment_status' => 'PNS',
                'nik' => '9999999999999999', // Harus difilter / diabaikan
                'nip' => '8888888888888888', // Harus difilter / diabaikan
            ])
            ->assertRedirect("/gtk/{$staff->id}")
            ->assertSessionHas('success');

        $staff->refresh();

        // Field lokal berhasil terupdate
        $this->assertSame('Nama Baru, S.Pd.', $staff->full_name_with_titles);
        $this->assertSame('0899999999', $staff->phone);
        $this->assertSame('PNS', $staff->employment_status);

        // Field terproteksi EMIS tidak terpengaruh sama sekali
        $this->assertSame('3201010101010001', $staff->nik);
        $this->assertSame('197501012000031001', $staff->nip);

        // Verifikasi audit log tercatat di activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'updated',
            'model_type' => Staff::class,
            'model_id' => $staff->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_data_ownership_service_identifies_editable_and_readonly_fields(): void
    {
        $service = new DataOwnershipService;

        $this->assertTrue($service->isEditable('staff', 'phone'));
        $this->assertTrue($service->isEditable('staff', 'full_name_with_titles'));

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
