<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat morph verifiable_id harus mampu menyimpan UUID.
 *
 * nullableMorphs() membuat verifiable_id bertipe unsignedBigInteger,
 * padahal Student memakai UUID. Di MySQL insert UUID akan gagal.
 * Kolom diubah menjadi uuid/char(36) dan index morph dibuat ulang.
 */
return new class extends Migration
{
    private const INDEX = 'document_verifications_verifiable_type_verifiable_id_index';

    public function up(): void
    {
        if ($this->alreadyUuid()) {
            return;
        }

        $this->dropMorphIndex();

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->uuid('verifiable_id')->nullable()->change();
        });

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->index(['verifiable_type', 'verifiable_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        $nonNumeric = DB::table('document_verifications')
            ->whereNotNull('verifiable_id')
            ->pluck('verifiable_id')
            ->reject(fn ($id) => ctype_digit((string) $id))
            ->count();

        if ($nonNumeric > 0) {
            throw new RuntimeException(
                "Terdapat {$nonNumeric} baris verifikasi dengan verifiable_id non-numerik. ".
                'Rollback ke bigint hanya aman pada lingkungan tanpa data UUID.'
            );
        }

        $this->dropMorphIndex();

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('verifiable_id')->nullable()->change();
        });

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->index(['verifiable_type', 'verifiable_id'], self::INDEX);
        });
    }

    private function alreadyUuid(): bool
    {
        $type = strtolower((string) Schema::getColumnType('document_verifications', 'verifiable_id'));

        return in_array($type, ['string', 'varchar', 'char', 'uuid'], true);
    }

    private function dropMorphIndex(): void
    {
        $exists = collect(Schema::getIndexes('document_verifications'))
            ->pluck('name')
            ->contains(self::INDEX);

        if (! $exists) {
            return;
        }

        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
