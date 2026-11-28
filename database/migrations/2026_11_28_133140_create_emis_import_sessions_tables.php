<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emis_import_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 20)->default('staff');
            $table->string('status', 20)->default('open')->index(); // open|completed|expired|failed
            $table->string('owner_key', 80)->index();
            $table->unsignedInteger('total_pages');
            $table->unsignedInteger('total_count')->nullable();
            $table->unsignedInteger('received_pages')->default(0);
            $table->unsignedInteger('received_rows')->default(0);
            $table->string('source', 40)->default('bookmarklet');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('emis_import_session_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->unsignedInteger('page');
            $table->unsignedInteger('items_count');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['session_id', 'page']);
            $table->foreign('session_id')->references('id')->on('emis_import_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emis_import_session_pages');
        Schema::dropIfExists('emis_import_sessions');
    }
};
