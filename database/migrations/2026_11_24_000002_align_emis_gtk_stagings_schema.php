<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selaraskan skema emis_gtk_stagings dengan kode yang sudah memakainya.
 *
 * Frontend dan parser sudah membaca nrg, peg_id, function_role,
 * is_sertifikasi, k_aktif, dan source, tetapi kolomnya belum pernah dibuat.
 * Nama kanonik tetap full_name_with_titles; nama pendek disediakan lewat
 * accessor pada model, bukan kolom ganda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'nrg' => fn (Blueprint $table) => $table->string('nrg', 30)->nullable(),
            'peg_id' => fn (Blueprint $table) => $table->string('peg_id', 50)->nullable(),
            'function_role' => fn (Blueprint $table) => $table->string('function_role', 100)->nullable(),
            'is_sertifikasi' => fn (Blueprint $table) => $table->boolean('is_sertifikasi')->nullable()->default(false),
            'k_aktif' => fn (Blueprint $table) => $table->boolean('k_aktif')->nullable()->default(true),
            'source' => fn (Blueprint $table) => $table->string('source', 30)->default('bookmarklet'),
            'last_seen_at' => fn (Blueprint $table) => $table->timestamp('last_seen_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('emis_gtk_stagings', $column)) {
                Schema::table('emis_gtk_stagings', $definition);
            }
        }

        $this->addIndexIfMissing('emis_gtk_stagings_status_index', ['status']);
        $this->addIndexIfMissing('emis_gtk_stagings_nuptk_index', ['nuptk']);
        $this->addIndexIfMissing('emis_gtk_stagings_nip_index', ['nip']);
    }

    public function down(): void
    {
        foreach ([
            'emis_gtk_stagings_status_index',
            'emis_gtk_stagings_nuptk_index',
            'emis_gtk_stagings_nip_index',
        ] as $index) {
            if ($this->indexExists($index)) {
                Schema::table('emis_gtk_stagings', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }

        foreach (['last_seen_at', 'source', 'k_aktif', 'is_sertifikasi', 'function_role', 'peg_id', 'nrg'] as $column) {
            if (Schema::hasColumn('emis_gtk_stagings', $column)) {
                Schema::table('emis_gtk_stagings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexIfMissing(string $name, array $columns): void
    {
        if ($this->indexExists($name)) {
            return;
        }

        Schema::table('emis_gtk_stagings', fn (Blueprint $table) => $table->index($columns, $name));
    }

    private function indexExists(string $name): bool
    {
        return collect(Schema::getIndexes('emis_gtk_stagings'))
            ->pluck('name')
            ->contains($name);
    }
};
