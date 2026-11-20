<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NIK tidak selalu tersedia saat commit EMIS GTK.
 *
 * Identitas GTK dapat berasal dari NUPTK, NIP, atau NPK. Menjadikan nik
 * nullable memungkinkan staf diimpor tanpa NIK, sementara unique index
 * tetap aktif (MySQL dan SQLite mengizinkan banyak NULL pada unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        $orphans = DB::table('staff')->whereNull('nik')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Terdapat {$orphans} baris staff tanpa NIK. Isi NIK terlebih dahulu sebelum rollback."
            );
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->string('nik', 16)->nullable(false)->change();
        });
    }
};
