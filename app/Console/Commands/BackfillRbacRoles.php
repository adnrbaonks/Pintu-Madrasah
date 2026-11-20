<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

class BackfillRbacRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rbac:backfill';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi katalog role & permission Spatie dan backfill seluruh pengguna';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Memulai sinkronisasi katalog role & permission Spatie...');

        $seeder = new RolesAndPermissionsSeeder;
        $seeder->run();

        $users = User::all();
        $this->info("Berhasil melakukan sinkronisasi untuk {$users->count()} pengguna.");

        foreach ($users as $user) {
            $roles = $user->getRoleNames()->implode(', ');
            $this->line("- {$user->name} ({$user->username}): [{$user->role}] => Roles Spatie: [{$roles}]");
        }

        return self::SUCCESS;
    }
}
