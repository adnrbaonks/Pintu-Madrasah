<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $event,
        public array $data,
        public ?string $onlySubscriptionId = null,
        public ?string $eventId = null,
        public ?string $outboxEventId = null,
        public ?string $leaseToken = null,
    ) {
        $this->eventId ??= (string) Str::uuid();
    }

    /**
     * Jadwal backoff eksponensial (detik) antar percobaan pengiriman.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 300, 900];
    }

    public function backoffSecondsForAttempt(int $attempt): int
    {
        $schedule = $this->backoff();

        return $schedule[max(0, min($attempt - 1, count($schedule) - 1))];
    }

    public function handle(?WebhookPublisher $publisher = null): void
    {
        $publisher ??= app(WebhookPublisher::class);

        if ($this->outboxEventId) {
            if ($this->leaseToken === null) {
                $current = OutboxEvent::find($this->outboxEventId);
                if ($current && $current->status === OutboxEvent::STATUS_PUBLISHED) {
                    return;
                }

                $this->leaseToken = 'job:'.($this->job?->getJobId() ?? Str::random(8));

                $owned = OutboxEvent::whereKey($this->outboxEventId)
                    ->where('status', OutboxEvent::STATUS_PENDING)
                    ->update([
                        'status' => OutboxEvent::STATUS_PROCESSING,
                        'reserved_at' => now(),
                        'lease_until' => now()->addSeconds(300),
                        'leased_by' => $this->leaseToken,
                    ]);

                if (! $owned && $this->onlySubscriptionId === null) {
                    Log::notice('[OUTBOX] Direct webhook job skipped because event is already claimed or completed', [
                        'outbox_id' => $this->outboxEventId,
                    ]);

                    return;
                }
            } else {
                $owned = OutboxEvent::whereKey($this->outboxEventId)
                    ->where('status', OutboxEvent::STATUS_PROCESSING)
                    ->where('leased_by', $this->leaseToken)
                    ->where('lease_until', '>', now())
                    ->update([
                        'reserved_at' => now(),
                        'lease_until' => now()->addSeconds(300),
                    ]);
            }

            if (! $owned) {
                Log::notice('[OUTBOX] Dropping stale webhook job without current lease', [
                    'outbox_id' => $this->outboxEventId,
                    'lease_token' => $this->leaseToken,
                ]);

                return;
            }
        }

        $query = WebhookSubscription::where('is_active', true)
            ->where('event', $this->event);

        if ($this->onlySubscriptionId) {
            $query->where('id', $this->onlySubscriptionId);
        }

        $subs = $query->get();

        if ($subs->isEmpty()) {
            $this->markOutboxPublished();

            return;
        }

        $failures = [];

        foreach ($subs as $sub) {
            $error = $this->deliver($sub, $publisher);
            if ($error !== null) {
                $failures[] = $error;
            }
        }

        $this->updateOutboxAfterAttempt($failures);

        if ($failures !== [] && $this->job && $this->job->getConnectionName() !== 'sync') {
            $this->release($this->backoffSecondsForAttempt($this->attempts()));
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->outboxEventId) {
            OutboxEvent::whereKey($this->outboxEventId)
                ->where('leased_by', $this->leaseToken)
                ->update([
                    'status' => OutboxEvent::STATUS_FAILED,
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'reserved_at' => null,
                    'lease_until' => null,
                    'leased_by' => null,
                ]);
        }

        if ($this->eventId) {
            WebhookDelivery::where('event_id', $this->eventId)
                ->where('delivered', false)
                ->update(['next_retry_at' => null]);
        }
    }

    private function deliver(WebhookSubscription $sub, WebhookPublisher $publisher): ?string
    {
        $existing = WebhookDelivery::query()
            ->where('webhook_subscription_id', $sub->id)
            ->where('event_id', $this->eventId)
            ->first();

        // Deduplikasi: bila event_id ini sudah pernah berhasil dikirim ke subscription ini, lewati
        if ($existing && $existing->delivered) {
            return null;
        }

        $maxAttempts = max((int) ($sub->max_attempts ?: 5), 1);
        $attempt = $existing ? ($existing->attempt + 1) : 1;

        if ($attempt > $maxAttempts) {
            return "Max attempts ({$maxAttempts}) reached for subscription {$sub->id}";
        }

        $payload = $publisher->buildPayload(
            event: $this->event,
            eventId: (string) $this->eventId,
            data: $this->data,
            subscription: $sub,
        );

        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $sigV1 = $publisher->signatureV1($body, $sub->secret);
        $sigV2 = $publisher->signatureV2($timestamp, $body, $sub->secret);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Id' => (string) $this->eventId,
                    'X-Webhook-Event' => $this->event,
                    'X-Webhook-Timestamp' => (string) $timestamp,
                    'X-Webhook-Signature' => $sigV1,
                    'X-Webhook-Signature-V2' => $sigV2,
                    'User-Agent' => 'PintuMadrasah-Webhook/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post($sub->target_url);

            $ok = $response->successful();
            $errorMsg = $ok ? null : 'HTTP '.$response->status();
            $nextRetryAt = (! $ok && $attempt < $maxAttempts)
                ? now()->addSeconds($this->backoffSecondsForAttempt($attempt))
                : null;

            WebhookDelivery::updateOrCreate(
                [
                    'webhook_subscription_id' => $sub->id,
                    'event_id' => $this->eventId,
                ],
                [
                    'outbox_event_id' => $this->outboxEventId,
                    'event' => $this->event,
                    'payload' => $payload,
                    'status_code' => $response->status(),
                    'response_body' => mb_substr($response->body(), 0, 2000),
                    'response_headers' => $publisher->redactHeaders($response->headers()),
                    'delivered' => $ok,
                    'attempt' => $attempt,
                    'next_retry_at' => $nextRetryAt,
                    'delivered_at' => $ok ? now() : null,
                    'error' => $errorMsg,
                ]
            );

            $sub->update([
                'last_triggered_at' => now(),
                'last_status' => $response->status(),
            ]);

            return $errorMsg;
        } catch (\Throwable $e) {
            $errorMsg = mb_substr($e->getMessage(), 0, 1000);
            $nextRetryAt = ($attempt < $maxAttempts)
                ? now()->addSeconds($this->backoffSecondsForAttempt($attempt))
                : null;

            Log::warning('[WEBHOOK] Gagal kirim', [
                'event' => $this->event,
                'event_id' => $this->eventId,
                'attempt' => $attempt,
                'url' => $sub->target_url,
                'error' => $errorMsg,
            ]);

            WebhookDelivery::updateOrCreate(
                [
                    'webhook_subscription_id' => $sub->id,
                    'event_id' => $this->eventId,
                ],
                [
                    'outbox_event_id' => $this->outboxEventId,
                    'event' => $this->event,
                    'payload' => $payload,
                    'status_code' => null,
                    'delivered' => false,
                    'attempt' => $attempt,
                    'next_retry_at' => $nextRetryAt,
                    'delivered_at' => null,
                    'error' => $errorMsg,
                ]
            );

            $sub->update([
                'last_triggered_at' => now(),
                'last_status' => null,
            ]);

            return $errorMsg;
        }
    }

    private function markOutboxPublished(): void
    {
        if (! $this->outboxEventId) {
            return;
        }

        OutboxEvent::whereKey($this->outboxEventId)
            ->where('leased_by', $this->leaseToken)
            ->where('lease_until', '>', now())
            ->update([
                'status' => OutboxEvent::STATUS_PUBLISHED,
                'last_error' => null,
                'reserved_at' => null,
                'lease_until' => null,
                'leased_by' => null,
            ]);
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function updateOutboxAfterAttempt(array $failures): void
    {
        if (! $this->outboxEventId) {
            return;
        }

        $outbox = OutboxEvent::query()
            ->whereKey($this->outboxEventId)
            ->where('leased_by', $this->leaseToken)
            ->where('lease_until', '>', now())
            ->first();
        if (! $outbox) {
            return;
        }

        $newAttempts = $outbox->attempts + 1;

        if ($failures === []) {
            $outbox->update([
                'status' => OutboxEvent::STATUS_PUBLISHED,
                'attempts' => $newAttempts,
                'last_error' => null,
                'reserved_at' => null,
                'lease_until' => null,
                'leased_by' => null,
            ]);

            return;
        }

        $isDeadLetter = $newAttempts >= $this->tries;

        $outbox->update([
            'status' => $isDeadLetter ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_PENDING,
            'attempts' => $newAttempts,
            'last_error' => mb_substr(implode('; ', $failures), 0, 1000),
            'available_at' => $isDeadLetter
                ? null
                : now()->addSeconds($this->backoffSecondsForAttempt($newAttempts)),
            'reserved_at' => null,
            'lease_until' => null,
            'leased_by' => null,
        ]);
    }
}
