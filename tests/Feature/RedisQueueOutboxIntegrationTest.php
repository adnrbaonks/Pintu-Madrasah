<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookJob;
use App\Models\OutboxEvent;
use App\Models\Staff;
use App\Models\Student;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\OutboxService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class RedisQueueOutboxIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $testQueueName = 'webhooks';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (! $this->isRedisReachable()) {
            $this->markTestSkipped('Redis server pada 127.0.0.1:6379 tidak terjangkau.');
        }

        // Isolasi nama antrean per test run agar tidak mengganggu antrean bersama atau worker lain
        $this->testQueueName = 'webhooks_test_'.Str::lower(Str::random(12));

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'queue.connections.redis.queue' => $this->testQueueName,
            'queue.connections.redis.retry_after' => 90,
            'services.webhooks.queue' => $this->testQueueName,
        ]);

        $this->cleanIsolatedTestQueueKeys();
    }

    protected function tearDown(): void
    {
        if ($this->isRedisReachable()) {
            $this->cleanIsolatedTestQueueKeys();
        }

        parent::tearDown();
    }

    public function test_redis_queue_processes_webhook_jobs_without_duplicate_delivery_when_outbox_processor_runs(): void
    {
        Http::fake([
            'https://satelit.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $queueConnection = Queue::connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queueConnection);
        $this->assertSame('redis', config('queue.default'));

        WebhookSubscription::create([
            'event' => 'student.created',
            'target_url' => 'https://satelit.example.com/redis-hook',
            'secret' => 'whsec_redis_integration_secret',
            'is_active' => true,
        ]);

        // 1. Buat siswa -> Observer mencatat outbox_event dan menaruh DispatchWebhookJob(leaseToken=null) di Redis queue terisolasi
        $student = Student::factory()->create([
            'name' => 'Siswa Redis Queue',
            'nisn' => '0088776655',
        ]);

        $outbox = OutboxEvent::where('aggregate_type', Student::class)
            ->where('aggregate_id', $student->id)
            ->where('event_type', 'student.created')
            ->firstOrFail();

        // Karena queue adalah Redis asynchronous nyata, event masih pending sebelum worker berjalan
        $this->assertSame(OutboxEvent::STATUS_PENDING, $outbox->status);
        $this->assertSame(1, $queueConnection->size($this->testQueueName));
        Http::assertNothingSent();

        // 2. Simulasi race condition: webhooks:process-outbox berjalan sebelum job pertama dipop dari Redis
        // process-outbox mengklaim lease dan menaruh job kedua (dengan leaseToken worker) di Redis
        Artisan::call('webhooks:process-outbox', ['--limit' => 10]);

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PROCESSING, $outbox->status);
        $this->assertNotNull($outbox->leased_by);
        $this->assertSame(2, $queueConnection->size($this->testQueueName));

        // 3. Worker memproses kedua job dari Redis queue
        $this->workRedisQueue($this->testQueueName, 2);

        $this->assertSame(0, $queueConnection->size($this->testQueueName));

        // Hanya 1 HTTP webhook yang terkirim (job tanpa lease dilewati karena event sudah diklaim, + deduplikasi event_id)
        Http::assertSentCount(1);

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertNull($outbox->leased_by);
        $this->assertNull($outbox->lease_until);

        $this->assertSame(1, WebhookDelivery::where('event_id', $outbox->id)->count());
    }

    public function test_redis_queue_outbox_retry_backoff_and_lease_recovery_with_real_worker(): void
    {
        Http::fake([
            'https://satelit.example.com/flaky' => Http::sequence()
                ->push(['error' => 'upstream_timeout'], 503)
                ->push(['ok' => true], 200),
        ]);

        $queueConnection = Queue::connection('redis');

        $sub = WebhookSubscription::create([
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/flaky',
            'secret' => 'whsec_redis_retry_secret',
            'is_active' => true,
            'max_attempts' => 5,
        ]);

        $outbox = OutboxEvent::create([
            'aggregate_type' => Staff::class,
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'staff.updated',
            'payload' => ['name' => 'Guru Uji Redis Retry'],
            'status' => OutboxEvent::STATUS_PENDING,
            'available_at' => now()->subSecond(),
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        // 1. Proses outbox -> klaim event dan dorong job ke Redis
        Artisan::call('webhooks:process-outbox', ['--limit' => 10]);
        $this->assertSame(1, $queueConnection->size($this->testQueueName));

        // 2. Worker Redis mengeksekusi attempt 1 (HTTP 503 gagal)
        $this->workRedisQueue($this->testQueueName, 1);
        $this->assertSame(0, $queueConnection->size($this->testQueueName));

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PENDING, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertNull($outbox->leased_by);
        $this->assertNotNull($outbox->available_at);
        $this->assertTrue($outbox->available_at->isFuture());

        $delivery = WebhookDelivery::where('webhook_subscription_id', $sub->id)
            ->where('event_id', $outbox->id)
            ->firstOrFail();
        $this->assertFalse($delivery->delivered);
        $this->assertSame(1, $delivery->attempt);

        // 3. Selama available_at masih di masa depan (backoff belum lewat), process-outbox tidak menjadwalkan ulang
        Artisan::call('webhooks:process-outbox', ['--limit' => 10]);
        $this->assertSame(0, $queueConnection->size($this->testQueueName));

        // 4. Majukan waktu melewati backoff (10 detik) -> process-outbox mengklaim kembali & worker mengirim attempt 2 (HTTP 200)
        $this->travel(15)->seconds();

        Artisan::call('webhooks:process-outbox', ['--limit' => 10]);
        $this->assertSame(1, $queueConnection->size($this->testQueueName));

        $this->workRedisQueue($this->testQueueName, 1);
        $this->assertSame(0, $queueConnection->size($this->testQueueName));

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $outbox->status);
        $this->assertSame(2, $outbox->attempts);
        $this->assertNull($outbox->last_error);

        $delivery->refresh();
        $this->assertTrue($delivery->delivered);
        $this->assertSame(2, $delivery->attempt);
        Http::assertSentCount(2);
    }

    public function test_redis_queue_stale_job_from_expired_lease_does_not_overwrite_new_worker_claim(): void
    {
        Http::fake([
            'https://satelit.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $queueConnection = Queue::connection('redis');
        $service = app(OutboxService::class);

        WebhookSubscription::create([
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/redis-fence',
            'secret' => 'whsec_redis_fence_secret',
            'is_active' => true,
        ]);

        $outbox = OutboxEvent::create([
            'aggregate_type' => Staff::class,
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'staff.updated',
            'payload' => ['name' => 'Guru Redis Lease Fence'],
            'status' => OutboxEvent::STATUS_PENDING,
            'available_at' => now()->subMinute(),
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        // 1. Worker A mengklaim event dengan lease pendek dan menaruh job di Redis
        $service->processPending(limit: 10, leaseSeconds: 10, workerId: 'redis-worker-a');
        $this->assertSame(1, $queueConnection->size($this->testQueueName));
        $this->assertSame('redis-worker-a', $outbox->fresh()->leased_by);

        // 2. Lease Worker A kedaluwarsa sebelum job Worker A dieksekusi; Worker B mengklaim event & menaruh job baru di Redis
        $this->travel(20)->seconds();

        $claimedByB = $service->claimNextBatch(limit: 10, leaseSeconds: 300, workerId: 'redis-worker-b');
        $this->assertCount(1, $claimedByB);
        $this->assertSame('redis-worker-b', $outbox->fresh()->leased_by);

        // Worker C mencoba mengklaim bersamaan saat lease Worker B masih aktif -> 0 event terambil
        $claimedByC = $service->claimNextBatch(limit: 10, leaseSeconds: 300, workerId: 'redis-worker-c');
        $this->assertCount(0, $claimedByC);

        DispatchWebhookJob::dispatch(
            event: $outbox->event_type,
            data: $outbox->payload ?? [],
            onlySubscriptionId: null,
            eventId: $outbox->id,
            outboxEventId: $outbox->id,
            leaseToken: 'redis-worker-b',
        )->onQueue($this->testQueueName);

        $this->assertSame(2, $queueConnection->size($this->testQueueName));

        // 3. Pop job pertama dari Redis (milik Worker A yang stale): harus di-drop tanpa menimpa klaim Worker B & tanpa HTTP
        $this->workRedisQueue($this->testQueueName, 1);
        $this->assertSame(1, $queueConnection->size($this->testQueueName));
        Http::assertNothingSent();

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PROCESSING, $outbox->status);
        $this->assertSame('redis-worker-b', $outbox->leased_by);

        // 4. Pop job kedua dari Redis (milik Worker B yang memegang lease aktif): berhasil mengirim HTTP & publish outbox
        $this->workRedisQueue($this->testQueueName, 1);
        $this->assertSame(0, $queueConnection->size($this->testQueueName));
        Http::assertSentCount(1);

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $outbox->status);
        $this->assertNull($outbox->leased_by);
        $this->assertNull($outbox->lease_until);
    }

    public function test_redis_queue_retry_failed_reprocesses_dead_letter_outbox_event_preserving_attempt_history(): void
    {
        Http::fake([
            'https://satelit.example.com/dead-letter-recovery' => Http::response(['recovered' => true], 200),
        ]);

        $queueConnection = Queue::connection('redis');

        $sub = WebhookSubscription::create([
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/dead-letter-recovery',
            'secret' => 'whsec_dead_letter_retry_secret',
            'is_active' => true,
            'max_attempts' => 5,
        ]);

        $outbox = OutboxEvent::create([
            'aggregate_type' => Staff::class,
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'staff.updated',
            'payload' => ['name' => 'Guru Dead Letter Retry'],
            'status' => OutboxEvent::STATUS_FAILED,
            'available_at' => null,
            'attempts' => 5,
            'max_attempts' => 5,
            'last_error' => 'HTTP 502 (dead-letter after 5 attempts)',
        ]);

        WebhookDelivery::create([
            'webhook_subscription_id' => $sub->id,
            'outbox_event_id' => $outbox->id,
            'event_id' => $outbox->id,
            'event' => 'staff.updated',
            'payload' => ['data' => ['name' => 'Guru Dead Letter Retry']],
            'status_code' => 502,
            'delivered' => false,
            'attempt' => 5,
            'error' => 'HTTP 502',
        ]);

        // 1. Tanpa --retry-failed, event dead-letter (failed, attempts=5) tidak boleh diambil
        Artisan::call('webhooks:process-outbox', ['--limit' => 10]);
        $this->assertSame(0, $queueConnection->size($this->testQueueName));
        $this->assertSame(OutboxEvent::STATUS_FAILED, $outbox->fresh()->status);

        // 2. Dengan --retry-failed, event dead-letter diambil, max_attempts dinaikkan dari 5 menjadi 6,
        // dan riwayat attempts sebelumnya (5) tidak di-reset secara liar
        Artisan::call('webhooks:process-outbox', ['--limit' => 10, '--retry-failed' => true]);
        $this->assertSame(1, $queueConnection->size($this->testQueueName));

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PROCESSING, $outbox->status);
        $this->assertSame(5, $outbox->attempts);
        $this->assertSame(6, $outbox->max_attempts);

        // 3. Worker Redis memproses job retry ke-6 hingga published tanpa menduplikasi baris WebhookDelivery
        $this->workRedisQueue($this->testQueueName, 1);
        $this->assertSame(0, $queueConnection->size($this->testQueueName));

        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $outbox->status);
        $this->assertSame(6, $outbox->attempts);
        $this->assertSame(6, $outbox->max_attempts);
        $this->assertNull($outbox->last_error);

        $deliveries = WebhookDelivery::where('event_id', $outbox->id)->get();
        $this->assertCount(1, $deliveries);
        $this->assertTrue($deliveries->first()->delivered);
        $this->assertSame(6, $deliveries->first()->attempt);
        Http::assertSentCount(1);
    }

    private function workRedisQueue(string $queue, int $jobs): void
    {
        for ($i = 0; $i < $jobs; $i++) {
            Artisan::call('queue:work', [
                'connection' => 'redis',
                '--once' => true,
                '--queue' => $queue,
                '--tries' => 5,
            ]);
        }
    }

    private function isRedisReachable(): bool
    {
        try {
            $pong = Redis::connection('default')->ping();

            return $pong === true || $pong === 1 || str_contains(strtoupper((string) $pong), 'PONG');
        } catch (\Throwable) {
            return false;
        }
    }

    private function cleanIsolatedTestQueueKeys(): void
    {
        $conn = Redis::connection('default');
        $q = $this->testQueueName;
        foreach (["queues:{$q}", "queues:{$q}:notify", "queues:{$q}:delayed", "queues:{$q}:reserved"] as $key) {
            $conn->del($key);
        }
    }
}
