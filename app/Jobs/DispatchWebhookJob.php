<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $event,
        public array $data,
        public ?string $onlySubscriptionId = null,
    ) {}

    public function handle(): void
    {
        $query = WebhookSubscription::where('is_active', true)
            ->where('event', $this->event);

        if ($this->onlySubscriptionId) {
            $query->where('id', $this->onlySubscriptionId);
        }

        $subs = $query->get();

        if ($subs->isEmpty()) {
            return;
        }

        $body = json_encode([
            'event' => $this->event,
            'occurred_at' => now()->toIso8601String(),
            'data' => $this->data,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $sub) {
            $this->deliver($sub, $body);
        }
    }

    private function deliver(WebhookSubscription $sub, string $body): void
    {
        $signature = 'sha256='.hash_hmac('sha256', $body, $sub->secret);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Event' => $this->event,
                    'X-Webhook-Signature' => $signature,
                    'User-Agent' => 'PintuMadrasah-Webhook/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post($sub->target_url);

            $ok = $response->successful();

            WebhookDelivery::create([
                'webhook_subscription_id' => $sub->id,
                'event' => $this->event,
                'payload' => json_decode($body, true),
                'status_code' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 2000),
                'delivered' => $ok,
                'error' => $ok ? null : 'HTTP '.$response->status(),
            ]);

            $sub->update([
                'last_triggered_at' => now(),
                'last_status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WEBHOOK] Gagal kirim', [
                'event' => $this->event,
                'url' => $sub->target_url,
                'error' => $e->getMessage(),
            ]);

            WebhookDelivery::create([
                'webhook_subscription_id' => $sub->id,
                'event' => $this->event,
                'payload' => json_decode($body, true),
                'delivered' => false,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            $sub->update(['last_triggered_at' => now(), 'last_status' => null]);
        }
    }
}
