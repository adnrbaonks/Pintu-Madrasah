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
        Schema::table('students', function (Blueprint $table) {
            $table->string('gdrive_photo_id')->nullable()->after('profile_photo');
            $table->text('gdrive_photo_url')->nullable()->after('gdrive_photo_id');
            $table->string('photo_upload_status')->default('pending')->after('gdrive_photo_url');
            $table->unsignedInteger('photo_upload_retries')->default(0)->after('photo_upload_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['gdrive_photo_id', 'gdrive_photo_url', 'photo_upload_status', 'photo_upload_retries']);
        });
    }
};
