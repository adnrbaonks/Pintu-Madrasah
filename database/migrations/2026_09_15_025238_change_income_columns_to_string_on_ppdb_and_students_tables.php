<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // For MySQL/MariaDB we can just alter column type directly.
        // If there's an issue with enum/string, Doctrine DBAL handles it if installed.
        // But Laravel 11 handles Native column modification easily.

        Schema::table('students', function (Blueprint $table) {
            $table->string('father_income')->nullable()->change();
            $table->string('mother_income')->nullable()->change();
        });

        Schema::table('ppdb_registrations', function (Blueprint $table) {
            $table->string('father_income')->nullable()->change();
            $table->string('mother_income')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Note: Data might be lost or mangled going from string back to unsignedInteger
            // We cast it if needed, or leave it. Usually we just write the basic change.
            $table->unsignedInteger('father_income')->nullable()->change();
            $table->unsignedInteger('mother_income')->nullable()->change();
        });

        Schema::table('ppdb_registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('father_income')->nullable()->change();
            $table->unsignedBigInteger('mother_income')->nullable()->change();
        });
    }
};
