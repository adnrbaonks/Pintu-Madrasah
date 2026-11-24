<?php

namespace App\Services;

use App\Jobs\DispatchWebhookJob;
use App\Models\OutboxEvent;
use App\Models\WebhookSubscription;
use App\Support\Webhook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OutboxService
{
    private static bool $suppressObserver = false;

    /**
     * Jadwal backoff eksponensial (detik) antar percobaan outbox.
     *
     * @return array<int, int>
     */
    public static function backoffSchedule(): array
    {
        return [10, 30, 120, 300, 900];
    }

    public static function backoffSeconds(int $attempt): int
    {
        $schedule = self::backoffSchedule();

        return $schedule[max(0, min($attempt - 1, count($schedule) - 1))];
    }

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
            'reserved_at' => null,
            'lease_until' => null,
            'leased_by' => null,
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
     * Klaim batch event berikutnya secara atomik untuk mencegah perebutan event oleh pekerja konkuren.
     * Mengubah status menjadi `processing` dan menetapkan lease timeout.
     *
     * @return Collection<int, OutboxEvent>
     */
    public function claimNextBatch(
        int $limit = 100,
        int $leaseSeconds = 300,
        ?string $workerId = null,
        bool $retryFailed = false,
    ): Collection {
        $workerId ??= gethostname().':'.getmypid().':'.Str::random(8);
        $leaseUntil = now()->addSeconds($leaseSeconds);

        return DB::transaction(function () use ($limit, $workerId, $leaseUntil, $retryFailed): Collection {
            $query = OutboxEvent::query()
                ->where(function ($q) use ($retryFailed) {
                    $q->where(function ($sub) {
                        $sub->where('status', OutboxEvent::STATUS_PENDING)
                            ->where(function ($avail) {
                                $avail->whereNull('available_at')->orWhere('available_at', '<=', now());
                            });
                    })->orWhere(function ($sub) {
                        $sub->where('status', OutboxEvent::STATUS_PROCESSING)
                            ->where(function ($lease) {
                                $lease->whereNotNull('lease_until')->where('lease_until', '<=', now());
                            });
                    });

                    if ($retryFailed) {
                        $q->orWhere('status', OutboxEvent::STATUS_FAILED);
                    }
                })
                ->where('attempts', '<', 5)
                ->orderBy('created_at')
                ->limit($limit);

            $events = $query->lockForUpdate()->get();

            if ($events->isEmpty()) {
                return new Collection;
            }

            $ids = $events->pluck('id')->all();

            OutboxEvent::whereIn('id', $ids)->update([
                'status' => OutboxEvent::STATUS_PROCESSING,
                'reserved_at' => now(),
                'lease_until' => $leaseUntil,
                'leased_by' => $workerId,
                'updated_at' => now(),
            ]);

            return OutboxEvent::whereIn('id', $ids)->get();
        });
    }

    /**
     * Pulihkan event yang terhenti (stalled) dalam status `processing` karena worker crash atau timeout.
     */
    public function recoverStalled(int $maxAttempts = 5): int
    {
        return DB::transaction(function () use ($maxAttempts): int {
            $events = OutboxEvent::query()
                ->where('status', OutboxEvent::STATUS_PROCESSING)
                ->whereNotNull('lease_until')
                ->where('lease_until', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($events as $event) {
                $newAttempts = $event->attempts + 1;
                $isDeadLetter = $newAttempts >= $maxAttempts;
                $event->update([
                    'status' => $isDeadLetter ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_PENDING,
                    'attempts' => $newAttempts,
                    'available_at' => $isDeadLetter ? null : now()->addSeconds(self::backoffSeconds($newAttempts)),
                    'reserved_at' => null,
                    'lease_until' => null,
                    'leased_by' => null,
                    'last_error' => $isDeadLetter
                        ? 'Lease expired; batas maksimum percobaan terlampaui (dead-letter).'
                        : 'Lease expired; dikembalikan ke antrean pending dengan backoff.',
                ]);
                Log::warning('[OUTBOX] Recovered stalled event', [
                    'outbox_id' => $event->id,
                    'event_type' => $event->event_type,
                    'attempts' => $newAttempts,
                    'dead_letter' => $isDeadLetter,
                ]);
            }

            return $events->count();
        });
    }

    /**
     * Proses antrean `outbox_events` dengan atomic claim, lease, recovery, dan retry backoff.
     */
    public function processPending(
        int $limit = 100,
        bool $retryFailed = false,
        int $leaseSeconds = 300,
        ?string $workerId = null,
    ): int {
        // 1. Klaim batch secara atomik
        $events = $this->claimNextBatch(
            limit: $limit,
            leaseSeconds: $leaseSeconds,
            workerId: $workerId,
            retryFailed: $retryFailed,
        );

        $processed = 0;

        foreach ($events as $outbox) {
            $hasActiveSubscriber = WebhookSubscription::query()
                ->where('is_active', true)
                ->where('event', $outbox->event_type)
                ->exists();

            if (! $hasActiveSubscriber) {
                OutboxEvent::whereKey($outbox->id)
                    ->where('leased_by', $outbox->leased_by)
                    ->where('lease_until', '>', now())
                    ->update([
                        'status' => OutboxEvent::STATUS_PUBLISHED,
                        'reserved_at' => null,
                        'lease_until' => null,
                        'leased_by' => null,
                        'last_error' => null,
                    ]);
                $processed++;

                continue;
            }

            DispatchWebhookJob::dispatch(
                event: $outbox->event_type,
                data: $outbox->payload ?? [],
                onlySubscriptionId: null,
                eventId: $outbox->id,
                outboxEventId: $outbox->id,
                leaseToken: $outbox->leased_by,
            )->onQueue('webhooks');

            $processed++;
        }

        return $processed;
    }
}
