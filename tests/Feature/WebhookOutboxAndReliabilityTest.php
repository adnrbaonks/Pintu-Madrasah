<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookJob;
use App\Models\ApiClient;
use App\Models\OutboxEvent;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\OutboxService;
use App\Services\WebhookPublisher;
use App\Services\WebhookUrlGuard;
use App\Support\Webhook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebhookOutboxAndReliabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_webhook_sends_v1_and_v2_signatures_and_strips_pii_by_default(): void
    {
        Http::fake([
            'https://satelit.example.com/*' => Http::response(['received' => true], 200, [
                'X-Server' => 'SIPSMAD',
                'Set-Cookie' => 'secret_session=12345',
            ]),
        ]);

        $subDefaultNoPii = WebhookSubscription::create([
            'event' => 'student.created',
            'target_url' => 'https://satelit.example.com/no-pii',
            'secret' => 'whsec_test_secret_1234567890',
            'is_active' => true,
            'include_pii' => false,
        ]);

        $subWithPii = WebhookSubscription::create([
            'event' => 'student.created',
            'target_url' => 'https://satelit.example.com/with-pii',
            'secret' => 'whsec_test_secret_1234567890',
            'is_active' => true,
            'include_pii' => true,
        ]);

        Student::factory()->create([
            'name' => 'Siswa Uji Webhook',
            'nisn' => '0099887766',
            'nik' => '3601012345678901',
        ]);

        $publisher = app(WebhookPublisher::class);

        Http::assertSentCount(2);

        // 1. Verifikasi subscription default (include_pii = false) tidak menerima NIK & signature v1/v2 valid
        Http::assertSent(function (HttpClientRequest $req) use ($subDefaultNoPii, $publisher): bool {
            if ($req->url() !== 'https://satelit.example.com/no-pii') {
                return false;
            }

            $rawBody = $req->body();
            $json = json_decode($rawBody, true);
            $timestamp = (int) $req->header('X-Webhook-Timestamp')[0];
            $sigV1 = $req->header('X-Webhook-Signature')[0];
            $sigV2 = $req->header('X-Webhook-Signature-V2')[0];
            $eventId = $req->header('X-Webhook-Id')[0];

            $this->assertSame($eventId, $json['event_id']);
            $this->assertSame(1, $json['version']);
            $this->assertArrayNotHasKey('nik', $json['data']);
            $this->assertArrayNotHasKey('nisn', $json['data']);
            $this->assertArrayNotHasKey('gender', $json['data']);
            $this->assertSame('Siswa Uji Webhook', $json['data']['name'] ?? null);
            $this->assertSame($publisher->signatureV1($rawBody, $subDefaultNoPii->secret), $sigV1);
            $this->assertTrue($publisher->verifySignatureV2($timestamp, $rawBody, $subDefaultNoPii->secret, $sigV2));
            $this->assertFalse($publisher->verifySignatureV2($timestamp, $rawBody, 'wrong_secret', $sigV2));

            return true;
        });

        // 2. Verifikasi subscription dengan include_pii = true tetap menerima NIK, NISN, dan gender
        Http::assertSent(function (HttpClientRequest $req) use ($subWithPii): bool {
            if ($req->url() !== $subWithPii->target_url) {
                return false;
            }

            $json = json_decode($req->body(), true);
            $this->assertSame('3601012345678901', $json['data']['nik'] ?? null);
            $this->assertSame('0099887766', $json['data']['nisn'] ?? null);
            $this->assertNotEmpty($json['data']['gender'] ?? null);

            return true;
        });

        // 3. Verifikasi response_headers sensitif (Set-Cookie) diredaksi di database
        $delivery = WebhookDelivery::where('webhook_subscription_id', $subDefaultNoPii->id)->firstOrFail();
        $this->assertTrue($delivery->delivered);
        $this->assertSame('[REDACTED]', $delivery->response_headers['Set-Cookie'] ?? null);
    }

    public function test_webhook_pii_sanitization_covers_staff_nested_payloads_and_api_client_scopes(): void
    {
        $publisher = app(WebhookPublisher::class);

        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'Ustadz Ahmad, S.Pd.',
            'nip' => '198501012010011001',
            'nuptk' => '1234567890123456',
            'npk' => '9876543210123',
            'employment_status' => 'pns',
            'functional_position' => 'Guru Matematika',
            'is_active' => true,
        ]);

        $rawPayload = Webhook::staffPayload($staff, [
            'changed_fields' => ['nip', 'nuptk', 'npk', 'functional_position', 'phone'],
            'nested_profile' => [
                'nik' => '3601011985010001',
                'nisn' => '0012345678',
                'nip' => '198501012010011001',
                'nuptk' => '1234567890123456',
                'npk' => '9876543210123',
                'gender' => 'L',
                'phone' => '08123456789',
                'email' => 'ahmad@madrasah.sch.id',
                'father_income' => 5000000,
                'unit_name' => 'MTsN 1 Pandeglang',
            ],
        ]);

        $subNoPii = WebhookSubscription::create([
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/staff-no-pii',
            'secret' => 'whsec_staff_no_pii',
            'is_active' => true,
            'include_pii' => false,
        ]);

        $builtNoPii = $publisher->buildPayload('staff.updated', 'evt-1', $rawPayload, $subNoPii);
        $dataNoPii = $builtNoPii['data'];

        // Field operasional tetap dipertahankan
        $this->assertSame($staff->id, $dataNoPii['id']);
        $this->assertSame('Ustadz Ahmad, S.Pd.', $dataNoPii['name']);
        $this->assertSame('pns', $dataNoPii['employment_status']);
        $this->assertSame('Guru Matematika', $dataNoPii['functional_position']);
        $this->assertTrue($dataNoPii['is_active']);
        $this->assertSame(['functional_position'], $dataNoPii['changed_fields']);
        $this->assertSame('MTsN 1 Pandeglang', $dataNoPii['nested_profile']['unit_name']);

        // Identitas & PII pribadi dihapus baik di top-level maupun nested object
        foreach (['nip', 'nuptk', 'npk', 'nik', 'nisn', 'gender', 'phone', 'email', 'father_income'] as $piiKey) {
            $this->assertArrayNotHasKey($piiKey, $dataNoPii);
            $this->assertArrayNotHasKey($piiKey, $dataNoPii['nested_profile']);
        }

        // Subscription dengan include_pii=true tetapi tertaut ke API Client tanpa scope staff:pii wajib disanitasi
        $clientWithoutPiiScope = ApiClient::create([
            'name' => 'Client Non PII',
            'api_key' => 'pm_live_non_pii_client_key_12345',
            'scopes' => ['staff:read'],
            'is_active' => true,
        ]);

        $subClientWithoutScope = WebhookSubscription::create([
            'api_client_id' => $clientWithoutPiiScope->id,
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/staff-unscoped',
            'secret' => 'whsec_staff_unscoped',
            'is_active' => true,
            'include_pii' => true,
        ]);

        $builtUnscoped = $publisher->buildPayload('staff.updated', 'evt-2', $rawPayload, $subClientWithoutScope);
        $this->assertArrayNotHasKey('nip', $builtUnscoped['data']);
        $this->assertArrayNotHasKey('nuptk', $builtUnscoped['data']);
        $this->assertArrayNotHasKey('npk', $builtUnscoped['data']);
        $this->assertArrayNotHasKey('nik', $builtUnscoped['data']['nested_profile']);

        // Subscription dengan include_pii=true dan API Client yang memiliki scope staff:pii berhak menerima PII
        $clientWithPiiScope = ApiClient::create([
            'name' => 'Client Staff PII',
            'api_key' => 'pm_live_staff_pii_client_key_12345',
            'scopes' => ['staff:read', 'staff:pii'],
            'is_active' => true,
        ]);

        $subClientWithScope = WebhookSubscription::create([
            'api_client_id' => $clientWithPiiScope->id,
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/staff-scoped',
            'secret' => 'whsec_staff_scoped',
            'is_active' => true,
            'include_pii' => true,
        ]);

        $builtScoped = $publisher->buildPayload('staff.updated', 'evt-3', $rawPayload, $subClientWithScope);
        $this->assertSame('198501012010011001', $builtScoped['data']['nip']);
        $this->assertSame('1234567890123456', $builtScoped['data']['nuptk']);
        $this->assertSame('9876543210123', $builtScoped['data']['npk']);
        $this->assertSame('3601011985010001', $builtScoped['data']['nested_profile']['nik']);
    }

    public function test_webhook_deduplication_retry_backoff_and_dead_letter(): void
    {
        $sub = WebhookSubscription::create([
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/staff-hook',
            'secret' => 'whsec_retry_secret',
            'is_active' => true,
            'max_attempts' => 2,
        ]);

        $outbox = OutboxEvent::create([
            'aggregate_type' => Staff::class,
            'aggregate_id' => '11111111-2222-3333-4444-555555555555',
            'event_type' => 'staff.updated',
            'payload' => ['id' => '11111111-2222-3333-4444-555555555555', 'name' => 'Guru Uji'],
            'status' => OutboxEvent::STATUS_PENDING,
            'available_at' => now(),
        ]);

        // Percobaan 1: Server satelit merespons HTTP 500
        Http::fake([
            'https://satelit.example.com/staff-hook' => Http::sequence()
                ->push(['error' => 'server down'], 500)
                ->push(['ok' => true], 200),
        ]);

        $job = new DispatchWebhookJob(
            event: 'staff.updated',
            data: $outbox->payload,
            onlySubscriptionId: $sub->id,
            eventId: $outbox->id,
            outboxEventId: $outbox->id,
        );
        $job->handle();

        $delivery = WebhookDelivery::where('webhook_subscription_id', $sub->id)
            ->where('event_id', $outbox->id)
            ->firstOrFail();

        $this->assertFalse($delivery->delivered);
        $this->assertSame(1, $delivery->attempt);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertSame(1, $outbox->fresh()->attempts);

        // Percobaan 2 (Replay via Admin Controller dengan event_id sama): Server merespons HTTP 200
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->post("/webhooks/deliveries/{$delivery->id}/retry")
            ->assertRedirect();

        $delivery->refresh();
        $this->assertTrue($delivery->delivered);
        $this->assertSame(2, $delivery->attempt);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $outbox->fresh()->status);

        // Percobaan 3 (Deduplikasi): Eksekusi ulang job dengan event_id yang sudah terkirim tidak mengirim HTTP baru
        $job->handle();
        Http::assertSentCount(2);
    }

    public function test_rolled_back_transaction_does_not_persist_outbox_or_send_webhook(): void
    {
        Http::fake();

        WebhookSubscription::create([
            'event' => 'student.created',
            'target_url' => 'https://satelit.example.com/rollback-test',
            'secret' => 'whsec_rollback_test',
            'is_active' => true,
        ]);

        try {
            DB::transaction(function (): void {
                Student::factory()->create([
                    'name' => 'Siswa Batal Simpan',
                    'nisn' => '0011223344',
                ]);

                throw new \RuntimeException('Simulasi kegagalan transaksi');
            });
        } catch (\RuntimeException) {
            // Ekspektasi rollback
        }

        $this->assertDatabaseMissing('students', ['nisn' => '0011223344']);
        $this->assertSame(0, OutboxEvent::where('event_type', 'student.created')->count());
        Http::assertNothingSent();
    }

    public function test_staff_observer_records_created_updated_and_deactivated_events_and_respects_suppression(): void
    {
        Http::fake([
            'https://satelit.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        WebhookSubscription::create([
            'event' => 'staff.deactivated',
            'target_url' => 'https://satelit.example.com/staff-deactivated',
            'secret' => 'whsec_staff_obs',
            'is_active' => true,
        ]);

        // 1. Pembuatan staff baru mencatat staff.created di outbox
        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'Ustadz Fauzan, M.Pd.',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_type' => Staff::class,
            'aggregate_id' => $staff->id,
            'event_type' => 'staff.created',
        ]);

        // 2. Penonaktifan staff mencatat staff.updated dan staff.deactivated serta mengirim webhook
        $staff->update(['is_active' => false]);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_type' => Staff::class,
            'aggregate_id' => $staff->id,
            'event_type' => 'staff.deactivated',
            'status' => OutboxEvent::STATUS_PUBLISHED,
        ]);

        Http::assertSentCount(1);

        // 3. Mode bulk suppression (OutboxService::withoutObserver) tidak membuat baris outbox baru
        $beforeCount = OutboxEvent::count();
        OutboxService::withoutObserver(function (): void {
            Staff::factory()->create(['full_name_with_titles' => 'Bulk Import GTK']);
        });
        $this->assertSame($beforeCount, OutboxEvent::count());
    }

    public function test_outbox_atomic_claim_and_concurrency_isolation(): void
    {
        $service = app(OutboxService::class);

        // Buat 10 event pending
        for ($i = 1; $i <= 10; $i++) {
            OutboxEvent::create([
                'aggregate_type' => 'App\Models\Staff',
                'aggregate_id' => (string) Str::uuid(),
                'event_type' => 'staff.updated',
                'payload' => ['seq' => $i],
                'status' => OutboxEvent::STATUS_PENDING,
                'available_at' => now()->subMinute(),
                'attempts' => 0,
            ]);
        }

        // Simulasi Pekerja A mengklaim 6 event
        $claimedA = $service->claimNextBatch(limit: 6, leaseSeconds: 120, workerId: 'worker-alpha');
        $this->assertCount(6, $claimedA);
        foreach ($claimedA as $event) {
            $this->assertSame(OutboxEvent::STATUS_PROCESSING, $event->status);
            $this->assertSame('worker-alpha', $event->leased_by);
            $this->assertNotNull($event->lease_until);
        }

        // Simulasi Pekerja B mengklaim 6 event secara konkuren
        $claimedB = $service->claimNextBatch(limit: 6, leaseSeconds: 120, workerId: 'worker-beta');
        $this->assertCount(4, $claimedB);
        foreach ($claimedB as $event) {
            $this->assertSame(OutboxEvent::STATUS_PROCESSING, $event->status);
            $this->assertSame('worker-beta', $event->leased_by);
        }

        // Pastikan tidak ada event yang tumpang tindih (disjoint set)
        $idsA = $claimedA->pluck('id')->all();
        $idsB = $claimedB->pluck('id')->all();
        $this->assertEmpty(array_intersect($idsA, $idsB));

        // Pekerja C yang datang berikutnya tidak mendapatkan event pending
        $claimedC = $service->claimNextBatch(limit: 5, leaseSeconds: 120, workerId: 'worker-gamma');
        $this->assertCount(0, $claimedC);
    }

    public function test_outbox_lease_expiry_and_recovery(): void
    {
        $service = app(OutboxService::class);

        // Event yang terhenti dalam status processing dan lease sudah lewat 5 menit lalu
        $stalled = OutboxEvent::create([
            'aggregate_type' => 'App\Models\Student',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'student.updated',
            'payload' => ['name' => 'Siswa Stalled'],
            'status' => OutboxEvent::STATUS_PROCESSING,
            'reserved_at' => now()->subMinutes(6),
            'lease_until' => now()->subMinute(),
            'leased_by' => 'crashed-worker-pid-999',
            'attempts' => 1,
        ]);

        $recovered = $service->recoverStalled(maxAttempts: 5);
        $this->assertSame(1, $recovered);

        $stalled->refresh();
        $this->assertSame(OutboxEvent::STATUS_PENDING, $stalled->status);
        $this->assertSame(2, $stalled->attempts);
        $this->assertNull($stalled->lease_until);
        $this->assertNull($stalled->leased_by);
        $this->assertNotNull($stalled->available_at);
        $this->assertTrue($stalled->available_at->isFuture());
    }

    public function test_outbox_stalled_max_attempts_moves_to_failed_dead_letter(): void
    {
        $service = app(OutboxService::class);

        $deadLetterEvent = OutboxEvent::create([
            'aggregate_type' => 'App\Models\Staff',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'staff.updated',
            'payload' => ['name' => 'Guru Dead Letter'],
            'status' => OutboxEvent::STATUS_PROCESSING,
            'lease_until' => now()->subMinute(),
            'leased_by' => 'old-worker',
            'attempts' => 4, // Upaya ke-5 akan menjadi dead letter
        ]);

        $recovered = $service->recoverStalled(maxAttempts: 5);
        $this->assertSame(1, $recovered);

        $deadLetterEvent->refresh();
        $this->assertSame(OutboxEvent::STATUS_FAILED, $deadLetterEvent->status);
        $this->assertSame(5, $deadLetterEvent->attempts);
        $this->assertNull($deadLetterEvent->lease_until);
        $this->assertStringContainsString('dead-letter', $deadLetterEvent->last_error);
    }

    public function test_outbox_asynchronous_queue_delivery_using_database_driver(): void
    {
        // Pastikan driver asynchronous (database) aktif, bukan sync
        config(['queue.default' => 'database']);

        Http::fake([
            'https://satelit.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $sub = WebhookSubscription::create([
            'event' => 'student.enrolled',
            'target_url' => 'https://satelit.example.com/enrolled',
            'secret' => 'whsec_async_queue_secret',
            'is_active' => true,
        ]);

        $outbox = OutboxEvent::create([
            'aggregate_type' => 'App\Models\Student',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'student.enrolled',
            'payload' => ['nisn' => '1234567890', 'name' => 'Siswa Antrean Async'],
            'status' => OutboxEvent::STATUS_PENDING,
            'available_at' => now(),
            'attempts' => 0,
        ]);

        $service = app(OutboxService::class);
        $processed = $service->processPending(limit: 10);
        $this->assertSame(1, $processed);

        // Verifikasi bahwa job benar-benar tersimpan di tabel database jobs (antrean asynchronous)
        $this->assertDatabaseHas('jobs', [
            'queue' => 'webhooks',
        ]);

        // Jalankan worker queue asynchronous secara eksplisit
        Artisan::call('queue:work', [
            '--once' => true,
            '--queue' => 'webhooks',
        ]);

        // Verifikasi webhook terkirim via HTTP dan outbox event berstatus published dengan lease bersih
        Http::assertSentCount(1);
        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $outbox->status);
        $this->assertNull($outbox->lease_until);
        $this->assertNull($outbox->leased_by);
        $this->assertSame(1, $outbox->attempts);

        // Tabel jobs kini kosong karena job selesai dieksekusi
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_process_outbox_command_and_scheduler_registration(): void
    {
        $code = Artisan::call('webhooks:process-outbox', [
            '--limit' => 50,
        ]);
        $this->assertSame(0, $code);

        // Verifikasi command terjadwal di routes/console.php dengan TTL withoutOverlapping eksplisit (5 menit)
        $schedule = app(Schedule::class);
        $scheduledEvent = collect($schedule->events())->first(function ($event) {
            return str_contains($event->command ?? '', 'webhooks:process-outbox');
        });

        $this->assertNotNull($scheduledEvent, 'Command webhooks:process-outbox harus terdaftar di scheduler.');
        $this->assertTrue($scheduledEvent->withoutOverlapping);
        $this->assertSame(5, $scheduledEvent->expiresAt);
    }

    public function test_outbox_lease_fencing_prevents_slow_worker_from_overwriting_stolen_lease(): void
    {
        Http::fake([
            'https://satelit.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        WebhookSubscription::create([
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/lease-fence',
            'secret' => 'whsec_fence_secret',
            'is_active' => true,
        ]);

        $outbox = OutboxEvent::create([
            'aggregate_type' => Staff::class,
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'staff.updated',
            'payload' => ['name' => 'Guru Lease Fence'],
            'status' => OutboxEvent::STATUS_PROCESSING,
            'reserved_at' => now()->subMinutes(10),
            'lease_until' => now()->subSeconds(5), // Lease worker-1 sudah expired (> 300 detik)
            'leased_by' => 'worker-1-slow',
            'attempts' => 1,
        ]);

        // Worker-2 mengklaim event yang lease-nya sudah expired
        $service = app(OutboxService::class);
        $claimedByWorker2 = $service->claimNextBatch(limit: 5, leaseSeconds: 300, workerId: 'worker-2-fast');
        $this->assertCount(1, $claimedByWorker2);
        $this->assertSame('worker-2-fast', $outbox->fresh()->leased_by);

        // Worker-1 yang lambat baru mencoba mengeksekusi job atau gagal dengan token lamanya ('worker-1-slow')
        $staleJob = new DispatchWebhookJob(
            event: 'staff.updated',
            data: $outbox->payload,
            onlySubscriptionId: null,
            eventId: $outbox->id,
            outboxEventId: $outbox->id,
            leaseToken: 'worker-1-slow',
        );
        $staleJob->handle();
        $staleJob->failed(new \RuntimeException('Worker 1 timeout'));

        // Pastikan status dan lease Worker-2 TIDAK tertimpa oleh Worker-1, dan tidak ada HTTP terkirim dari staleJob
        Http::assertNothingSent();
        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PROCESSING, $outbox->status);
        $this->assertSame('worker-2-fast', $outbox->leased_by);

        // Sekarang Worker-2 mengeksekusi job dengan lease token yang sah ('worker-2-fast')
        $validJob = new DispatchWebhookJob(
            event: 'staff.updated',
            data: $outbox->payload,
            onlySubscriptionId: null,
            eventId: $outbox->id,
            outboxEventId: $outbox->id,
            leaseToken: 'worker-2-fast',
        );
        $validJob->handle();

        Http::assertSentCount(1);
        $outbox->refresh();
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $outbox->status);
        $this->assertNull($outbox->leased_by);
    }

    public function test_webhook_subscription_secret_is_encrypted_at_rest_and_hidden_from_inertia_and_json_responses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/webhooks', [
            'event' => 'student.created',
            'target_url' => 'https://satelit.example.com/secure-secret-hook',
            'description' => 'Uji proteksi secret',
            'include_pii' => false,
            'max_attempts' => 5,
        ]);

        $response->assertRedirect();
        $flashMessage = (string) session('success');
        $this->assertStringContainsString('whsec_', $flashMessage);

        $sub = WebhookSubscription::where('target_url', 'https://satelit.example.com/secure-secret-hook')->firstOrFail();

        // 1. Secret harus tersembunyi dari toArray() dan toJson()
        $this->assertArrayNotHasKey('secret', $sub->toArray());
        $this->assertStringNotContainsString('whsec_', $sub->toJson());

        // 2. Di database (at-rest), nilai raw secret harus terenkripsi (bukan plaintext whsec_*)
        $rawSecret = (string) DB::table('webhook_subscriptions')
            ->where('id', $sub->id)
            ->value('secret');
        $this->assertNotSame($sub->secret, $rawSecret);
        $this->assertStringNotContainsString('whsec_', $rawSecret);
        $this->assertSame('encrypted', Setting::encryptionState($rawSecret));

        // 3. Halaman daftar webhook (Inertia) tidak boleh memuat field secret
        $this->actingAs($admin)
            ->get('/webhooks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Webhooks/Index', false)
                ->has('subscriptions', 1)
                ->missing('subscriptions.0.secret')
            );

        // 4. Migrasi idempoten untuk secret lama berformat plaintext
        $legacyId = (string) Str::uuid();
        DB::table('webhook_subscriptions')->insert([
            'id' => $legacyId,
            'event' => 'staff.updated',
            'target_url' => 'https://satelit.example.com/legacy-secret-hook',
            'secret' => 'whsec_legacy_plaintext_secret_123456',
            'is_active' => true,
            'include_pii' => false,
            'max_attempts' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migratedCount = WebhookSubscription::migratePlaintextSecrets();
        $this->assertSame(1, $migratedCount);
        $this->assertSame(0, WebhookSubscription::migratePlaintextSecrets());

        $legacySub = WebhookSubscription::findOrFail($legacyId);
        $this->assertSame('whsec_legacy_plaintext_secret_123456', $legacySub->secret);
    }

    public function test_webhook_ssrf_protection_blocks_private_loopback_metadata_and_dns_rebinding(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $blockedUrls = [
            'http://satelit.example.com/plain-http',
            'https://localhost/webhook',
            'https://127.0.0.1/webhook',
            'https://10.0.0.15/webhook',
            'https://172.16.10.5/webhook',
            'https://192.168.1.20/webhook',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/webhook',
            'https://[fc00::1]/webhook',
            'https://[fe80::1]/webhook',
            'https://internal-redis/webhook',
            'https://service.internal/webhook',
        ];

        foreach ($blockedUrls as $blockedUrl) {
            $this->actingAs($admin)
                ->post('/webhooks', [
                    'event' => 'student.created',
                    'target_url' => $blockedUrl,
                ])
                ->assertSessionHasErrors('target_url');
        }

        $this->assertSame(0, WebhookSubscription::count());

        // Simulasikan DNS rebinding saat runtime pengiriman: URL terdaftar dengan domain publik,
        // tetapi saat DispatchWebhookJob berjalan DNS mengarah ke 127.0.0.1 / 169.254.169.254.
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $sub = WebhookSubscription::create([
            'event' => 'student.created',
            'target_url' => 'https://rebinding.example.com/hook',
            'secret' => 'whsec_rebinding_test',
            'is_active' => true,
        ]);

        $reboundGuard = new WebhookUrlGuard(fn (string $host): array => ['169.254.169.254']);

        $job = new DispatchWebhookJob(
            event: 'student.created',
            data: ['id' => 'stu-ssrf', 'name' => 'Siswa SSRF'],
            onlySubscriptionId: $sub->id,
            eventId: (string) Str::uuid(),
        );

        $job->handle(app(WebhookPublisher::class), $reboundGuard);

        Http::assertNothingSent();

        $delivery = WebhookDelivery::where('webhook_subscription_id', $sub->id)->firstOrFail();
        $this->assertFalse($delivery->delivered);
        $this->assertNull($delivery->next_retry_at);
        $this->assertStringContainsString('SSRF Guard:', (string) $delivery->error);
    }

    public function test_webhook_delivery_rejects_http_redirects(): void
    {
        Http::fake([
            'https://satelit.example.com/redirect-hook' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);

        $sub = WebhookSubscription::create([
            'event' => 'student.created',
            'target_url' => 'https://satelit.example.com/redirect-hook',
            'secret' => 'whsec_redirect_test',
            'is_active' => true,
        ]);

        $job = new DispatchWebhookJob(
            event: 'student.created',
            data: ['id' => 'stu-redirect', 'name' => 'Siswa Redirect'],
            onlySubscriptionId: $sub->id,
            eventId: (string) Str::uuid(),
        );
        $job->handle();

        Http::assertSentCount(1);
        $delivery = WebhookDelivery::where('webhook_subscription_id', $sub->id)->firstOrFail();
        $this->assertFalse($delivery->delivered);
        $this->assertSame(302, $delivery->status_code);
        $this->assertStringContainsString('redirect rejected', (string) $delivery->error);
    }
}
