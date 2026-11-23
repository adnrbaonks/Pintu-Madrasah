<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrasi aman seluruh secret lama yang masih berformat plaintext
        Setting::migratePlaintextSecrets();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Enkripsi adalah pengamanan satu arah; tidak didekripsi saat rollback demi alasan keamanan.
    }
};
