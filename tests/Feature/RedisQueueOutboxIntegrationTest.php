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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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

    public function test_two_parallel_worker_processes_claim_leases_recover_expired_lease_and_deliver_without_duplicates(): void
    {
        $driver = DB::connection()->getDriverName();
        $tempSqlitePath = null;

        if ($driver === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') {
            // Untuk SQLite in-memory, buat file SQLite sementara yang terisolasi agar dua proses OS paralel berbagi state DB yang sama
            $tempSqlitePath = sys_get_temp_dir().'/pintu_parallel_outbox_'.Str::lower(Str::random(12)).'.sqlite';
            touch($tempSqlitePath);

            config(['database.connections.sqlite.database' => $tempSqlitePath]);
            DB::purge('sqlite');
            DB::reconnect('sqlite');

            Artisan::call('migrate', ['--force' => true]);
        } elseif ($driver === 'mysql') {
            // Pastikan transaksi RefreshDatabase di-commit agar proses worker anak (OS process terpisah) dapat melihat baris uji
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->commit();
            }
        }

        $createdOutboxIds = [];
        $subscriptionId = null;

        try {
            $sub = WebhookSubscription::create([
                'event' => 'staff.updated',
                'target_url' => 'https://satelit.example.com/parallel-hook',
                'secret' => 'whsec_parallel_workers_secret_key_123456',
                'is_active' => true,
                'max_attempts' => 5,
            ]);
            $subscriptionId = $sub->id;

            // Buat 4 event pending + 1 event dengan lease kedaluwarsa dari worker lama yang crash
            for ($i = 1; $i <= 4; $i++) {
                $event = OutboxEvent::create([
                    'aggregate_type' => Staff::class,
                    'aggregate_id' => (string) Str::uuid(),
                    'event_type' => 'staff.updated',
                    'payload' => ['name' => "Guru Paralel #{$i}"],
                    'status' => OutboxEvent::STATUS_PENDING,
                    'available_at' => now()->subSeconds(5),
                    'attempts' => 0,
                    'max_attempts' => 5,
                ]);
                $createdOutboxIds[] = $event->id;
            }

            $expiredLeaseEvent = OutboxEvent::create([
                'aggregate_type' => Staff::class,
                'aggregate_id' => (string) Str::uuid(),
                'event_type' => 'staff.updated',
                'payload' => ['name' => 'Guru Lease Expired Recovery'],
                'status' => OutboxEvent::STATUS_PROCESSING,
                'leased_by' => 'crashed-worker-stale',
                'lease_until' => now()->subMinutes(3),
                'available_at' => now()->subMinutes(5),
                'attempts' => 1,
                'max_attempts' => 5,
            ]);
            $createdOutboxIds[] = $expiredLeaseEvent->id;

            // Dorong juga duplicate unleased job ke Redis untuk menguji deduplikasi saat dua worker berjalan paralel
            foreach ($createdOutboxIds as $eventId) {
                DispatchWebhookJob::dispatch(
                    event: 'staff.updated',
                    data: ['name' => 'Duplicate Unleased Job'],
                    onlySubscriptionId: null,
                    eventId: $eventId,
                    outboxEventId: $eventId,
                    leaseToken: null,
                )->onQueue($this->testQueueName);
            }

            $workerScriptPath = sys_get_temp_dir().'/pintu_worker_runner_'.Str::lower(Str::random(10)).'.php';
            file_put_contents($workerScriptPath, $this->buildParallelWorkerScript());

            $dbConnection = config('database.default');
            $dbDatabase = $tempSqlitePath ?? config("database.connections.{$dbConnection}.database");

            $procA = new Process([
                PHP_BINARY,
                $workerScriptPath,
                base_path(),
                $this->testQueueName,
                'parallel-worker-a',
                (string) $dbConnection,
                (string) $dbDatabase,
            ]);
            $procB = new Process([
                PHP_BINARY,
                $workerScriptPath,
                base_path(),
                $this->testQueueName,
                'parallel-worker-b',
                (string) $dbConnection,
                (string) $dbDatabase,
            ]);

            $procA->setTimeout(60);
            $procB->setTimeout(60);

            // Jalankan kedua proses OS worker secara bersamaan (benar-benar paralel)
            $procA->start();
            $procB->start();

            $procA->wait();
            $procB->wait();

            @unlink($workerScriptPath);

            $this->assertTrue($procA->isSuccessful(), 'Worker A gagal: '.$procA->getErrorOutput().' '.$procA->getOutput());
            $this->assertTrue($procB->isSuccessful(), 'Worker B gagal: '.$procB->getErrorOutput().' '.$procB->getOutput());

            $outA = json_decode(trim($procA->getOutput()), true);
            $outB = json_decode(trim($procB->getOutput()), true);

            $this->assertIsArray($outA);
            $this->assertIsArray($outB);

            // Klaim lease oleh Worker A dan Worker B harus saling eksklusif (disjoint) dan totalnya tepat 5 event
            $claimedIdsA = $outA['claimed_ids'] ?? [];
            $claimedIdsB = $outB['claimed_ids'] ?? [];

            $this->assertEmpty(
                array_intersect($claimedIdsA, $claimedIdsB),
                'Tidak boleh ada OutboxEvent yang diklaim ganda oleh dua proses worker paralel.'
            );
            $this->assertCount(5, array_unique(array_merge($claimedIdsA, $claimedIdsB)));

            // Pastikan seluruh 5 event (termasuk 1 event lease expired) berstatus published dan hanya memiliki 1 delivery
            foreach ($createdOutboxIds as $eventId) {
                $event = OutboxEvent::findOrFail($eventId);
                $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $event->status);
                $this->assertNull($event->leased_by);
                $this->assertNull($event->lease_until);

                $deliveries = WebhookDelivery::where('event_id', $eventId)->get();
                $this->assertCount(1, $deliveries);
                $this->assertTrue($deliveries->first()->delivered);
                $this->assertSame(200, $deliveries->first()->status_code);
            }

            $this->assertSame(0, Queue::connection('redis')->size($this->testQueueName));
        } finally {
            if ($driver === 'mysql') {
                WebhookDelivery::whereIn('event_id', $createdOutboxIds)->delete();
                OutboxEvent::whereIn('id', $createdOutboxIds)->delete();
                if ($subscriptionId) {
                    WebhookSubscription::whereKey($subscriptionId)->delete();
                }
                DB::connection()->beginTransaction();
            } elseif ($tempSqlitePath !== null && is_file($tempSqlitePath)) {
                @unlink($tempSqlitePath);
            }
        }
    }

    private function buildParallelWorkerScript(): string
    {
        return <<<'PHP'
<?php
$basePath = $argv[1];
$queueName = $argv[2];
$workerId = $argv[3];
$dbConnection = $argv[4];
$dbDatabase = $argv[5];

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config([
    'database.default' => $dbConnection,
    "database.connections.{$dbConnection}.database" => $dbDatabase,
    'queue.default' => 'redis',
    'queue.connections.redis.connection' => 'default',
    'queue.connections.redis.queue' => $queueName,
    'services.webhooks.queue' => $queueName,
]);

Illuminate\Support\Facades\DB::purge($dbConnection);
Illuminate\Support\Facades\DB::reconnect($dbConnection);

if ($dbConnection === 'sqlite') {
    Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 5000');
}

Illuminate\Support\Facades\Http::fake([
    'https://satelit.example.com/*' => Illuminate\Support\Facades\Http::response(['ok' => true], 200),
]);

$app->bind(App\Services\WebhookUrlGuard::class, fn () => new App\Services\WebhookUrlGuard(
    fn (string $host): array => ['93.184.216.34']
));

// Barrier sinkronisasi di Redis agar kedua worker memulai klaim pada saat yang bersamaan
$redis = Illuminate\Support\Facades\Redis::connection('default');
$barrierKey = "queues:{$queueName}:barrier";
$readyCount = (int) $redis->incr($barrierKey);
$redis->expire($barrierKey, 30);

$waitStart = microtime(true);
while ((int) $redis->get($barrierKey) < 2 && (microtime(true) - $waitStart) < 3.0) {
    usleep(5000);
}

$outboxService = $app->make(App\Services\OutboxService::class);
$claimedIds = [];

for ($attempt = 0; $attempt < 5; $attempt++) {
    try {
        $claimed = $outboxService->claimNextBatch(limit: 5, leaseSeconds: 120, workerId: $workerId);
        foreach ($claimed as $event) {
            $claimedIds[] = $event->id;
            App\Jobs\DispatchWebhookJob::dispatch(
                event: $event->event_type,
                data: $event->payload ?? [],
                onlySubscriptionId: null,
                eventId: $event->id,
                outboxEventId: $event->id,
                leaseToken: $workerId,
            )->onQueue($queueName);
        }
        break;
    } catch (\Throwable $e) {
        if (str_contains(strtolower($e->getMessage()), 'locked') || str_contains(strtolower($e->getMessage()), 'deadlock')) {
            usleep(20000);
            continue;
        }
        throw $e;
    }
}

$processedJobs = 0;
for ($i = 0; $i < 10; $i++) {
    if (Illuminate\Support\Facades\Queue::connection('redis')->size($queueName) === 0) {
        usleep(15000);
        if (Illuminate\Support\Facades\Queue::connection('redis')->size($queueName) === 0) {
            break;
        }
    }

    Illuminate\Support\Facades\Artisan::call('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--queue' => $queueName,
        '--tries' => 5,
    ]);
    $processedJobs++;
}

echo json_encode([
    'worker_id' => $workerId,
    'claimed_ids' => $claimedIds,
    'processed_jobs' => $processedJobs,
]);
PHP;
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
        foreach (["queues:{$q}", "queues:{$q}:notify", "queues:{$q}:delayed", "queues:{$q}:reserved", "queues:{$q}:barrier"] as $key) {
            $conn->del($key);
        }
    }
}
