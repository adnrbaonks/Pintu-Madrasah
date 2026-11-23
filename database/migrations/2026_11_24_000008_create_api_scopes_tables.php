<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->string('group', 50)->default('general');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('api_client_scope', function (Blueprint $table) {
            $table->foreignUuid('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->foreignUuid('api_scope_id')->constrained('api_scopes')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['api_client_id', 'api_scope_id']);
        });

        $now = now();
        $defaultScopes = [
            ['code' => 'students:read', 'name' => 'Baca data siswa', 'group' => 'students'],
            ['code' => 'students:pii', 'name' => 'Baca data sensitif (PII) siswa', 'group' => 'students'],
            ['code' => 'staff:read', 'name' => 'Baca data guru & staf', 'group' => 'staff'],
            ['code' => 'staff:pii', 'name' => 'Baca data sensitif (PII) guru & staf', 'group' => 'staff'],
            ['code' => 'classes:read', 'name' => 'Baca data rombel & kelas', 'group' => 'academic'],
            ['code' => 'academic-years:read', 'name' => 'Baca tahun ajaran aktif', 'group' => 'academic'],
        ];

        foreach ($defaultScopes as $scope) {
            DB::table('api_scopes')->insert([
                'id' => (string) Str::uuid(),
                'code' => $scope['code'],
                'name' => $scope['name'],
                'group' => $scope['group'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_client_scope');
        Schema::dropIfExists('api_scopes');
    }
};
