<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiClientController extends Controller
{
    public function index(): Response
    {
        $clients = ApiClient::orderBy('name')->get()->append('masked_key');

        return Inertia::render('ApiClients/Index', [
            'clients' => $clients,
            'availableScopes' => ApiClient::SCOPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:api_clients,name',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'in:' . implode(',', array_keys(ApiClient::SCOPES)),
            'notes' => 'nullable|string|max:255',
        ]);

        $client = ApiClient::create([
            ...$data,
            'api_key' => 'pm_live_' . Str::random(48),
        ]);

        return redirect()->back()->with(
            'success',
            "API Key untuk {$client->name} dibuat: {$client->api_key} — salin sekarang, key penuh hanya tampil sekali di sini."
        );
    }

    public function toggleActive(ApiClient $apiClient): RedirectResponse
    {
        $apiClient->update(['is_active' => ! $apiClient->is_active]);

        $status = $apiClient->is_active ? 'diaktifkan' : 'dinonaktifkan';
        return redirect()->back()->with('success', "API Key {$apiClient->name} {$status}.");
    }

    public function regenerate(ApiClient $apiClient): RedirectResponse
    {
        $apiClient->update(['api_key' => 'pm_live_' . Str::random(48)]);

        return redirect()->back()->with(
            'success',
            "API Key baru untuk {$apiClient->name}: {$apiClient->fresh()->api_key} — key lama langsung mati."
        );
    }

    public function destroy(ApiClient $apiClient): RedirectResponse
    {
        $apiClient->delete();

        return redirect()->back()->with('success', "API Key {$apiClient->name} dihapus permanen.");
    }
}
