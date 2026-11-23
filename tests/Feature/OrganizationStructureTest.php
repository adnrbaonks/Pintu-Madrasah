<?php

namespace Tests\Feature;

use App\Models\OrganizationUnit;
use App\Models\OutboxEvent;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\OrganizationService;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationStructureTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_organization_index_renders_units_positions_assignments_and_approvers(): void
    {
        $this->seed(OrganizationSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/organisasi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Organization/Index', false)
                ->has('units', 5)
                ->has('positions', 9)
                ->has('assignments')
                ->has('approvers')
            );
    }

    public function test_can_create_and_update_organization_unit_and_position_and_blocks_deletion_when_in_use(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/organisasi/unit', [
                'parent_id' => null,
                'code' => 'PIMP',
                'name' => 'Pimpinan Madrasah',
                'type' => 'pimpinan',
                'is_active' => true,
            ])
            ->assertRedirect();

        $unit = OrganizationUnit::where('code', 'PIMP')->firstOrFail();

        $this->actingAs($admin)
            ->post('/organisasi/jabatan', [
                'org_unit_id' => $unit->id,
                'code' => 'KAMAD',
                'name' => 'Kepala Madrasah',
                'level' => 1,
                'is_structural' => true,
            ])
            ->assertRedirect();

        $position = Position::where('code', 'KAMAD')->firstOrFail();

        // Unit tidak boleh dihapus jika masih memiliki jabatan
        $this->actingAs($admin)
            ->delete("/organisasi/unit/{$unit->id}")
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('org_units', ['id' => $unit->id]);
        $this->assertDatabaseHas('positions', ['id' => $position->id]);
    }

    public function test_position_assignment_creates_audit_log_and_outbox_and_rejects_overlapping_primary_assignments(): void
    {
        $this->seed(OrganizationSeeder::class);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $staff = Staff::factory()->create(['full_name_with_titles' => 'H. Ahmad Fauzi, M.Ag.']);

        $kamadPos = Position::where('code', 'KAMAD')->firstOrFail();
        $wakaKurPos = Position::where('code', 'WAKA-KUR')->firstOrFail();

        // 1. Penugasan utama pertama (2025-01-01 s/d 2026-12-31)
        $this->actingAs($kaurTu)
            ->post('/organisasi/penugasan', [
                'staff_id' => $staff->id,
                'position_id' => $kamadPos->id,
                'org_unit_id' => $kamadPos->org_unit_id,
                'sk_number' => 'SK/KAMAD/01/2025',
                'valid_from' => '2025-01-01',
                'valid_until' => '2026-12-31',
                'is_primary' => true,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('position_assignments', [
            'staff_id' => $staff->id,
            'position_id' => $kamadPos->id,
            'sk_number' => 'SK/KAMAD/01/2025',
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'created_position_assignment',
            'model_type' => PositionAssignment::class,
        ]);

        $outbox = OutboxEvent::where('event_type', 'staff.position.assigned')
            ->where('aggregate_id', $staff->id)
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame('KAMAD', $outbox->payload['position_code']);

        // 2. Penugasan utama kedua yang tumpang tindih tanggal (2026-06-01 s/d 2027-06-01) -> wajib ditolak!
        $this->actingAs($kaurTu)
            ->post('/organisasi/penugasan', [
                'staff_id' => $staff->id,
                'position_id' => $wakaKurPos->id,
                'org_unit_id' => $wakaKurPos->org_unit_id,
                'sk_number' => 'SK/WAKA/02/2026',
                'valid_from' => '2026-06-01',
                'valid_until' => '2027-06-01',
                'is_primary' => true,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('is_primary');

        // 3. Penugasan tambahan (non-primary / is_primary = false) pada periode yang sama -> diizinkan
        $this->actingAs($kaurTu)
            ->post('/organisasi/penugasan', [
                'staff_id' => $staff->id,
                'position_id' => $wakaKurPos->id,
                'org_unit_id' => $wakaKurPos->org_unit_id,
                'sk_number' => 'SK/PLT-WAKA/03/2026',
                'valid_from' => '2026-06-01',
                'valid_until' => '2026-12-31',
                'is_primary' => false,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // 4. Penugasan utama baru setelah periode lama berakhir (2027-01-01) -> diizinkan
        $this->actingAs($kaurTu)
            ->post('/organisasi/penugasan', [
                'staff_id' => $staff->id,
                'position_id' => $wakaKurPos->id,
                'org_unit_id' => $wakaKurPos->org_unit_id,
                'sk_number' => 'SK/WAKA/04/2027',
                'valid_from' => '2027-01-01',
                'valid_until' => null,
                'is_primary' => true,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(3, PositionAssignment::where('staff_id', $staff->id)->count());
    }

    public function test_historical_lookup_and_approver_resolution_by_effective_date(): void
    {
        $this->seed(OrganizationSeeder::class);

        $kamad2024 = Staff::factory()->create(['full_name_with_titles' => 'Drs. H.Lukman Hakim (Kamad 2024)']);
        $kamad2026 = Staff::factory()->create(['full_name_with_titles' => 'Dr. H. Ridwan Kamil, M.Pd. (Kamad 2026)']);
        $kaurTu2026 = Staff::factory()->create(['full_name_with_titles' => 'Hj. Siti Aminah, S.Ag. (Kaur TU)']);

        $kamadPos = Position::where('code', 'KAMAD')->firstOrFail();
        $kaurTuPos = Position::where('code', 'KAUR-TU')->firstOrFail();

        $service = app(OrganizationService::class);

        $service->assignPosition([
            'staff_id' => $kamad2024->id,
            'position_id' => $kamadPos->id,
            'org_unit_id' => $kamadPos->org_unit_id,
            'sk_number' => 'SK/2024/001',
            'valid_from' => '2024-01-01',
            'valid_until' => '2025-12-31',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $service->assignPosition([
            'staff_id' => $kamad2026->id,
            'position_id' => $kamadPos->id,
            'org_unit_id' => $kamadPos->org_unit_id,
            'sk_number' => 'SK/2026/001',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'is_primary' => true,
            'status' => 'active',
        ]);

        $service->assignPosition([
            'staff_id' => $kaurTu2026->id,
            'position_id' => $kaurTuPos->id,
            'org_unit_id' => $kaurTuPos->org_unit_id,
            'sk_number' => 'SK/2026/002',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'is_primary' => true,
            'status' => 'active',
        ]);

        // Lookup historis pada pertengahan 2024
        $resolved2024 = $service->resolveApproverByLevel(1, null, '2024-06-15');
        $this->assertNotNull($resolved2024);
        $this->assertSame($kamad2024->id, $resolved2024->id);

        // Lookup pada tahun 2026
        $resolvedHead2026 = $service->resolveApproverByLevel(1, null, '2026-11-23');
        $resolvedTu2026 = $service->resolveApproverByLevel(2, null, '2026-11-23');

        $this->assertNotNull($resolvedHead2026);
        $this->assertSame($kamad2026->id, $resolvedHead2026->id);

        $this->assertNotNull($resolvedTu2026);
        $this->assertSame($kaurTu2026->id, $resolvedTu2026->id);
    }

    public function test_rbac_kepala_madrasah_can_view_organization_but_cannot_mutate_and_guru_is_forbidden(): void
    {
        $this->seed(OrganizationSeeder::class);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $guru = User::factory()->create(['role' => 'guru']);

        $this->actingAs($kamad)
            ->get('/organisasi')
            ->assertOk();

        $this->actingAs($kamad)
            ->post('/organisasi/unit', [
                'code' => 'TEST',
                'name' => 'Unit Test',
                'type' => 'unit',
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->actingAs($guru)
            ->get('/organisasi')
            ->assertForbidden();
    }
}
