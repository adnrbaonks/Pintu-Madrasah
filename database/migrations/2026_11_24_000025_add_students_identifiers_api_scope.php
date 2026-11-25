<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_scopes')) {
            return;
        }

        $exists = DB::table('api_scopes')->where('code', 'students:identifiers')->exists();
        if (! $exists) {
            DB::table('api_scopes')->insert([
                'id' => (string) Str::uuid(),
                'code' => 'students:identifiers',
                'name' => 'Baca kode identitas nasional siswa (NISN)',
                'group' => 'students',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('api_scopes')) {
            DB::table('api_scopes')->where('code', 'students:identifiers')->delete();
        }
    }
};
