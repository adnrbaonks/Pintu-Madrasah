<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SubjectSeeder::class,
        ]);

        // Akun admin awal hanya dibuat di local/testing, atau bila password
        // disediakan eksplisit lewat SEED_ADMIN_PASSWORD. Jangan pernah
        // menanam password default di staging/production.
        $password = config('seeding.admin_password');

        if (! app()->environment(['local', 'testing']) && ! $password) {
            return;
        }

        $admin = User::firstOrCreate(
            ['username' => config('seeding.admin_username', 'admin')],
            [
                'name' => 'Administrator',
                'password' => $password ?: 'password',
                'role' => 'admin',
                'is_active' => true,
            ],
        );

        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }
    }
}
