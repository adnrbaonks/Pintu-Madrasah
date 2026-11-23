<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('type', 50)->default('unit')->comment('pimpinan | tata_usaha | akademik | kesiswaan | kurikulum | sarana | unit');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('parent_id');
            $table->index(['is_active', 'type']);
        });

        Schema::table('org_units', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('org_units')
                ->nullOnDelete();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_unit_id')->constrained('org_units')->cascadeOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('level')->default(3)->comment('1: Kepala Madrasah, 2: Kaur TU / Waka, 3: Koordinator / Wali / Staf, 4: Pelaksana');
            $table->boolean('is_structural')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index(['org_unit_id', 'level']);
            $table->index('is_structural');
        });

        Schema::create('position_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('org_unit_id')->constrained('org_units')->cascadeOnDelete();
            $table->string('sk_number')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 30)->default('active')->comment('active | ended | revoked');
            $table->timestamps();

            $table->index(['staff_id', 'status', 'is_primary']);
            $table->index(['position_id', 'status']);
            $table->index(['org_unit_id', 'status']);
            $table->index(['valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_assignments');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('org_units');
    }
};
