<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DataDictionaryAndRoleMatrixTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        (new RolesAndPermissionsSeeder)->run();
    }

    public function test_data_dictionary_page_renders_for_authorized_roles_and_blocks_guru(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $guru = User::factory()->create(['role' => 'guru']);

        $this->actingAs($operator)
            ->get('/pengaturan/kamus-data')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/DataDictionary', false)
                ->has('dictionary.staff')
                ->has('dictionary.student')
                ->has('dictionary.academic')
            );

        $this->actingAs($guru)
            ->get('/pengaturan/kamus-data')
            ->assertForbidden();
    }

    public function test_role_matrix_page_renders_for_admin_and_blocks_non_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);

        $this->actingAs($admin)
            ->get('/pengaturan/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Roles/Index', false)
                ->has('roles', 7)
                ->has('catalog')
            );

        $this->actingAs($kaurTu)
            ->get('/pengaturan/roles')
            ->assertForbidden();
    }

    public function test_admin_can_update_non_admin_role_permissions_and_records_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $pegawaiRole = Role::findByName('pegawai');

        $this->actingAs($admin)
            ->put("/pengaturan/roles/{$pegawaiRole->id}", [
                'permissions' => ['view_academic_years', 'print_documents', 'view_letters'],
            ])
            ->assertRedirect('/pengaturan/roles')
            ->assertSessionHas('success');

        $this->assertTrue($pegawaiRole->fresh()->hasPermissionTo('view_letters'));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'updated_role_permissions',
            'model_type' => Role::class,
        ]);
    }

    public function test_admin_role_permissions_are_locked_from_modification(): void
    {
        $admin = User::factory()->admin()->create();
        $adminRole = Role::findByName('admin');

        $this->actingAs($admin)
            ->put("/pengaturan/roles/{$adminRole->id}", [
                'permissions' => ['view_students'],
            ])
            ->assertStatus(422);
    }
}
