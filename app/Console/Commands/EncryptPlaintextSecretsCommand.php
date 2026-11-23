<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class EncryptPlaintextSecretsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:encrypt-secrets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate legacy plaintext secrets to encrypted at rest storage in settings table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Memeriksa dan mengenkripsi secret lama yang masih plaintext...');

        $migrated = Setting::migratePlaintextSecrets();

        $this->info("Migrasi selesai. {$migrated} secret terenkripsi ke database.");

        return Command::SUCCESS;
    }
}
