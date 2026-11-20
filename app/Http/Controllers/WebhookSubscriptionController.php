<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchWebhookJob;
use App\Models\ApiClient;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebhookSubscriptionController extends Controller
{
    public function index(): Response
    {
        $subs = WebhookSubscription::with('apiClient:id,name')
            ->orderBy('event')
            ->orderBy('created_at')
            ->get();

        $deliveries = WebhookDelivery::with('subscription:id,event,target_url')
            ->latest()
            ->limit(50)
            ->get();

        return Inertia::render('Webhooks/Index', [
            'subscriptions' => $subs,
            'deliveries' => $deliveries,
            'events' => WebhookSubscription::EVENTS,
            'clients' => ApiClient::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_client_id' => 'nullable|uuid|exists:api_clients,id',
            'event' => 'required|in:'.implode(',', array_keys(WebhookSubscription::EVENTS)),
            'target_url' => 'required|url|max:500',
        ]);

        $sub = WebhookSubscription::create([
            ...$data,
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
}
