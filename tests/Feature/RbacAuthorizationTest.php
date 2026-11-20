<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
    }

    public function test_user_creation_automatically_assigns_spatie_role(): void
    {
        $user = User::factory()->create(['role' => 'staf']);

        $this->assertTrue($user->hasRole('staf'));
        $this->assertFalse($user->hasRole('operator'));
    }

    public function test_user_role_update_syncs_spatie_role(): void
    {
        $user = User::factory()->create(['role' => 'guru']);
        $this->assertTrue($user->hasRole('guru'));

        $user->update(['role' => 'operator']);
        $this->assertTrue($user->hasRole('operator'));
        $this->assertFalse($user->hasRole('guru'));
    }

    public function test_guru_cannot_access_student_management_routes(): void
    {
        $guru = User::factory()->create(['role' => 'guru']);

        $this->actingAs($guru)
            ->get('/students')
            ->assertForbidden();

        $this->actingAs($guru)
            ->get('/users')
            ->assertForbidden();
    }

    public function test_staf_can_access_student_management_but_not_users_page(): void
    {
        $staf = User::factory()->create(['role' => 'staf']);

        $this->actingAs($staf)
            ->get('/students')
            ->assertOk();

        $this->actingAs($staf)
            ->get('/users')
            ->assertForbidden();
    }

    public function test_admin_can_access_users_management(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk();
    }

    public function test_shared_inertia_auth_props_include_roles_and_permissions(): void
    {
        $staf = User::factory()->create(['role' => 'staf']);

        $this->actingAs($staf)
            ->get('/')
            ->assertOk();

        $this->assertTrue($staf->hasPermissionTo('view_students'));
        $this->assertTrue($staf->hasPermissionTo('manage_mutations'));
        $this->assertFalse($staf->hasPermissionTo('manage_users'));
    }

    public function test_rbac_backfill_command_assigns_correct_roles(): void
    {
        $user1 = User::factory()->create(['role' => 'staf']);
        $user2 = User::factory()->create(['role' => 'operator']);
        $user3 = User::factory()->create(['role' => 'kaur_tu']);

        $this->artisan('rbac:backfill')
            ->assertSuccessful();

        $this->assertTrue($user1->fresh()->hasRole('staf'));
        $this->assertTrue($user2->fresh()->hasRole('operator'));
        $this->assertTrue($user3->fresh()->hasRole('kaur_tu'));
    }
}
