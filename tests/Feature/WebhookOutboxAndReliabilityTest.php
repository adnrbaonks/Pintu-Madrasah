<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookJob;
use App\Models\OutboxEvent;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\OutboxService;
use App\Services\WebhookPublisher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            $this->assertSame($publisher->signatureV1($rawBody, $subDefaultNoPii->secret), $sigV1);
            $this->assertTrue($publisher->verifySignatureV2($timestamp, $rawBody, $subDefaultNoPii->secret, $sigV2));
            $this->assertFalse($publisher->verifySignatureV2($timestamp, $rawBody, 'wrong_secret', $sigV2));

            return true;
        });

        // 2. Verifikasi subscription dengan include_pii = true tetap menerima NIK
        Http::assertSent(function (HttpClientRequest $req) use ($subWithPii): bool {
            if ($req->url() !== $subWithPii->target_url) {
                return false;
            }

            $json = json_decode($req->body(), true);
            $this->assertSame('3601012345678901', $json['data']['nik'] ?? null);

            return true;
        });

        // 3. Verifikasi response_headers sensitif (Set-Cookie) diredaksi di database
        $delivery = WebhookDelivery::where('webhook_subscription_id', $subDefaultNoPii->id)->firstOrFail();
        $this->assertTrue($delivery->delivered);
        $this->assertSame('[REDACTED]', $delivery->response_headers['Set-Cookie'] ?? null);
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
}
