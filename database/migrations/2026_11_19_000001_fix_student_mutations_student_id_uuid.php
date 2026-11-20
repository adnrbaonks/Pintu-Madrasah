<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perbaiki kolom student_mutations.student_id pada instalasi lama.
 *
 * Migrasi asli memakai foreignId() (bigint) padahal students.id adalah UUID.
 * MySQL menolak foreign key tersebut. Instalasi baru sudah diperbaiki di
 * migrasi aslinya; migrasi ini hanya bertindak bila kolom masih bigint.
 *
 * SQLite dilewati karena bersifat dinamis (sudah menyimpan UUID sebagai teks)
 * dan operasi ubah kolom + foreign key tidak diperlukan untuk lingkungan lokal.
 */
return new class extends Migration
{
    private const FK = 'student_mutations_student_id_foreign';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! $this->columnIsBigint()) {
            return;
        }

        // Jangan ubah tipe bila ada student_id lama yang tidak dapat dipetakan
        // ke students.id (UUID). Data seperti ini harus dimigrasikan manual.
        $orphans = DB::table('student_mutations')
            ->whereNotNull('student_id')
            ->whereNotIn('student_id', DB::table('students')->select('id'))
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "student_mutations.student_id memiliki {$orphans} baris yang tidak cocok dengan students.id. ".
                'Petakan data tersebut secara manual sebelum menjalankan migrasi ini.'
            );
        }

        $foreignKeys = collect(Schema::getForeignKeys('student_mutations'))->pluck('name');

        if ($foreignKeys->contains(self::FK)) {
            Schema::table('student_mutations', function (Blueprint $table) {
                $table->dropForeign(['student_id']);
            });
        }

        Schema::table('student_mutations', function (Blueprint $table) {
            $table->uuid('student_id')->nullable()->change();
        });

        Schema::table('student_mutations', function (Blueprint $table) {
            $table->foreign('student_id')
                ->references('id')
                ->on('students')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($this->columnIsBigint()) {
            return;
        }

        $foreignKeys = collect(Schema::getForeignKeys('student_mutations'))->pluck('name');

        if ($foreignKeys->contains(self::FK)) {
            Schema::table('student_mutations', function (Blueprint $table) {
                $table->dropForeign(['student_id']);
            });
        }

        // Rollback hanya aman bila tidak ada data UUID tersimpan.
        Schema::table('student_mutations', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
        });

        Schema::table('student_mutations', function (Blueprint $table) {
            $table->foreign('student_id')
                ->references('id')
                ->on('students')
                ->nullOnDelete();
        });
    }

    private function columnIsBigint(): bool
    {
        $column = DB::selectOne(
            'select data_type as data_type from information_schema.columns
             where table_schema = database()
               and table_name = ?
               and column_name = ?',
            ['student_mutations', 'student_id']
        );

        return $column !== null && in_array($column->data_type, ['bigint', 'int', 'integer'], true);
    }
};
