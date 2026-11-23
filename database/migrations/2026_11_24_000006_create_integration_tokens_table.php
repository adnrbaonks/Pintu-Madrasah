<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 50);
            $table->string('token_prefix', 16)->index();
            $table->string('token_hash', 64)->unique();
            $table->string('token_last4', 8)->nullable();
            $table->string('label', 100);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'revoked_at']);
            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_tokens');
    }
};
