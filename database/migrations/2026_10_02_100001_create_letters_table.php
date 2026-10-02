<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->comment('masuk | keluar');
            $table->string('number')->comment('Nomor surat — auto generate untuk keluar, manual untuk masuk');
            $table->string('number_seq')->nullable()->comment('Nomor urut untuk auto numbering');
            $table->string('subject')->comment('Perihal / judul surat');
            $table->string('category', 50)->default('umum')
                ->comment('umum | undangan | sk | keterangan | rekomendasi | permohonan | pemberitahuan | edaran | lainnya');
            $table->date('letter_date')->comment('Tanggal surat');
            $table->date('received_date')->nullable()->comment('Tanggal diterima (surat masuk)');
            $table->string('from_to')->comment('Dari / Kepada');
            $table->text('description')->nullable()->comment('Isi ringkas / catatan');
            $table->string('attachment_path')->nullable()->comment('Path file lampiran (local storage)');
            $table->string('status', 20)->default('active')->comment('active | archived | disposisi');
            $table->text('disposisi')->nullable()->comment('Catatan disposisi pimpinan');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'letter_date']);
            $table->index(['type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};
