<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app()[PermissionRegistrar::class];

        // Reset cached roles and permissions
        $registrar->forgetCachedPermissions();

        // create permissions
        $permissions = [
            'view_students', 'create_students', 'edit_students', 'delete_students',
            'view_staff', 'create_staff', 'edit_staff', 'delete_staff',
            'view_academic_years', 'manage_academic_years',
            'view_rombels', 'manage_rombels',
            'view_audit_logs',
            'manage_settings',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        // findOrCreate() may run against a cached collection; reload before
        // assigning so a repeated seed is idempotent.
        $registrar->forgetCachedPermissions();

        // create roles and assign created permissions

        // Guru
        $role = Role::findOrCreate('guru');
        $role->givePermissionTo(['view_students', 'view_rombels', 'view_academic_years']);

        // Staf TU
        $role = Role::findOrCreate('staf');
        $role->givePermissionTo([
            'view_students', 'create_students', 'edit_students',
            'view_staff',
            'view_rombels', 'manage_rombels',
            'view_academic_years',
        ]);

        // Super Admin
        $role = Role::findOrCreate('admin');
        $role->givePermissionTo(Permission::all());

        // Assign 'admin' role to existing admin users
        $adminUsers = User::where('role', 'admin')->get();
        foreach ($adminUsers as $user) {
            if (! $user->hasRole('admin')) {
                $user->assignRole('admin');
            }
        }
    }
}
