<?php

use App\Models\WebhookSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_subscriptions')) {
            Schema::table('webhook_subscriptions', function (Blueprint $table) {
                $table->text('secret')->change();
            });

            WebhookSubscription::migratePlaintextSecrets();
        }
    }

    public function down(): void
    {
        // Enkripsi at-rest adalah pengamanan satu arah; kolom dibiarkan tetap bertipe text.
    }
};
