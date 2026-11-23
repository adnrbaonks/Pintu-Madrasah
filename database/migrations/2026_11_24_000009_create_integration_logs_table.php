<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel', 50)->index();
            $table->string('event', 80)->index();
            $table->string('status', 30)->default('success');
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('integration_token_id')->nullable()->index();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
