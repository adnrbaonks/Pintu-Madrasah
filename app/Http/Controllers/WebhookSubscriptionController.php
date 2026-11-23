<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchWebhookJob;
use App\Models\ApiClient;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebhookSubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $subs = WebhookSubscription::with('apiClient:id,name')
            ->orderBy('event')
            ->orderBy('created_at')
            ->get();

        $deliveryQuery = WebhookDelivery::with('subscription:id,event,target_url')
            ->latest();

        if ($request->filled('event')) {
            $deliveryQuery->where('event', (string) $request->query('event'));
        }

        if ($request->filled('status')) {
            $deliveryQuery->where('delivered', $request->query('status') === 'delivered');
        }

        $deliveries = $deliveryQuery->limit(50)->get();

        $outboxStats = [
            'pending' => OutboxEvent::where('status', OutboxEvent::STATUS_PENDING)->count(),
            'published' => OutboxEvent::where('status', OutboxEvent::STATUS_PUBLISHED)->count(),
            'failed' => OutboxEvent::where('status', OutboxEvent::STATUS_FAILED)->count(),
        ];

        return Inertia::render('Webhooks/Index', [
            'subscriptions' => $subs,
            'deliveries' => $deliveries,
            'events' => WebhookSubscription::EVENTS,
            'clients' => ApiClient::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'outboxStats' => $outboxStats,
            'filters' => [
                'event' => (string) $request->query('event', ''),
                'status' => (string) $request->query('status', ''),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_client_id' => 'nullable|uuid|exists:api_clients,id',
            'event' => 'required|in:'.implode(',', array_keys(WebhookSubscription::EVENTS)),
            'target_url' => 'required|url|max:500',
            'description' => 'nullable|string|max:255',
            'include_pii' => 'sometimes|boolean',
            'max_attempts' => 'sometimes|integer|min:1|max:10',
        ]);

        $sub = WebhookSubscription::create([
            'api_client_id' => $data['api_client_id'] ?? null,
            'event' => $data['event'],
            'target_url' => $data['target_url'],
            'description' => $data['description'] ?? null,
            'include_pii' => (bool) ($data['include_pii'] ?? false),
            'max_attempts' => (int) ($data['max_attempts'] ?? 5),
            'secret' => 'whsec_'.Str::random(48),
        ]);

        return redirect()->back()->with(
            'success',
            "Webhook {$sub->event} terdaftar. Secret: {$sub->secret} — salin ke server satelit untuk verifikasi signature."
        );
    }

    public function toggleActive(WebhookSubscription $webhook): RedirectResponse
    {
        $webhook->update(['is_active' => ! $webhook->is_active]);

        return redirect()->back()->with('success', 'Status webhook diperbarui.');
    }

    public function regenerateSecret(WebhookSubscription $webhook): RedirectResponse
    {
        $webhook->update(['secret' => 'whsec_'.Str::random(48)]);

        return redirect()->back()->with(
            'success',
            "Secret baru: {$webhook->fresh()->secret} — update di server satelit."
        );
    }

    public function destroy(WebhookSubscription $webhook): RedirectResponse
    {
        $webhook->delete();

        return redirect()->back()->with('success', 'Webhook dihapus.');
    }

    /** Kirim payload uji ke satu subscription (tanpa menunggu event asli) */
    public function test(WebhookSubscription $webhook): RedirectResponse
    {
        DispatchWebhookJob::dispatch($webhook->event, [
            'test' => true,
            'message' => 'Ping uji dari Pintu Madrasah. Jika satelit menerima ini, integrasi beres.',
        ], $webhook->id)->onQueue('webhooks');

        return redirect()->back()->with('success', "Ping uji dikirim ke {$webhook->target_url}. Cek log pengiriman di bawah.");
    }

    /** Kirim ulang (replay) delivery yang gagal menggunakan event_id yang sama */
    public function retryDelivery(WebhookDelivery $delivery): RedirectResponse
    {
        if ($delivery->delivered) {
            return redirect()->back()->with('success', 'Pengiriman ini sudah berhasil sebelumnya (deduplikasi aktif).');
        }

        $payloadData = is_array($delivery->payload)
            ? ($delivery->payload['data'] ?? $delivery->payload)
            : [];

        DispatchWebhookJob::dispatch(
            event: $delivery->event,
            data: is_array($payloadData) ? $payloadData : [],
            onlySubscriptionId: $delivery->webhook_subscription_id,
            eventId: $delivery->event_id ?: (string) Str::uuid(),
            outboxEventId: $delivery->outbox_event_id,
        )->onQueue('webhooks');

        return redirect()->back()->with('success', "Pengiriman ulang (attempt berikutnya) dijadwalkan untuk event {$delivery->event}.");
    }
}
