<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ppdb_documents', function (Blueprint $table) {
            $table->string('gdrive_file_id')->nullable()->after('file_path');
            $table->text('gdrive_view_url')->nullable()->after('gdrive_file_id');
            $table->string('status')->default('pending')->after('gdrive_view_url');
            $table->unsignedInteger('upload_retries')->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ppdb_documents', function (Blueprint $table) {
            $table->dropColumn(['gdrive_file_id', 'gdrive_view_url', 'status', 'upload_retries']);
        });
    }
};
