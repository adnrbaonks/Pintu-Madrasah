<?php

namespace App\Services;

use App\Jobs\DispatchWebhookJob;
use App\Models\OutboxEvent;
use App\Models\WebhookSubscription;
use App\Support\Webhook;
use Illuminate\Support\Facades\DB;

class OutboxService
{
    private static bool $suppressObserver = false;

    /**
     * Jalankan callback tanpa memicu observer outbox (mis. saat bulk import EMIS).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutObserver(callable $callback): mixed
    {
        $previous = self::$suppressObserver;
        self::$suppressObserver = true;

        try {
            return $callback();
        } finally {
            self::$suppressObserver = $previous;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressObserver;
    }

    /**
     * Catat event secara atomik ke tabel `outbox_events` di dalam transaksi aktif,
     * lalu jadwalkan pengiriman webhook setelah transaksi berhasil commit.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        bool $dispatchAfterCommit = true,
    ): OutboxEvent {
        // Cegah duplikasi baris outbox bila Observer dan Service mencatat event yang sama dalam satu siklus mutasi
        $existing = OutboxEvent::query()
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)
            ->where('event_type', $eventType)
            ->where('status', OutboxEvent::STATUS_PENDING)
            ->where('created_at', '>=', now()->subSeconds(2))
            ->latest()
            ->first();

        if ($existing) {
            $mergedPayload = array_merge($existing->payload ?? [], $payload);
            $existing->update(['payload' => $mergedPayload]);

            return $existing->fresh();
        }

        $outbox = OutboxEvent::create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => OutboxEvent::STATUS_PENDING,
            'available_at' => now(),
            'attempts' => 0,
        ]);

        if ($dispatchAfterCommit) {
            DB::afterCommit(function () use ($outbox): void {
                $fresh = OutboxEvent::find($outbox->id);
                if (! $fresh) {
                    return;
                }

                Webhook::fire(
                    event: $fresh->event_type,
                    data: $fresh->payload ?? [],
                    eventId: $fresh->id,
                    outboxEventId: $fresh->id,
                );
            });
        }

        return $outbox;
    }

    /**
     * Proses antrean `outbox_events` yang masih pending (atau gagal jika $retryFailed = true).
     */
    public function processPending(int $limit = 100, bool $retryFailed = false): int
    {
        $statuses = $retryFailed
            ? [OutboxEvent::STATUS_PENDING, OutboxEvent::STATUS_FAILED]
            : [OutboxEvent::STATUS_PENDING];

        $events = OutboxEvent::query()
            ->whereIn('status', $statuses)
            ->where(function ($q) {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->where('attempts', '<', 5)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($events as $outbox) {
            $hasActiveSubscriber = WebhookSubscription::query()
                ->where('is_active', true)
                ->where('event', $outbox->event_type)
                ->exists();

            if (! $hasActiveSubscriber) {
                $outbox->update(['status' => OutboxEvent::STATUS_PUBLISHED]);
                $processed++;

                continue;
            }

            DispatchWebhookJob::dispatch(
                event: $outbox->event_type,
                data: $outbox->payload ?? [],
                onlySubscriptionId: null,
                eventId: $outbox->id,
                outboxEventId: $outbox->id,
            )->onQueue('webhooks');

            $processed++;
        }

        return $processed;
    }
}
