<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiClientController extends Controller
{
    public function __construct(
        private ApiKeyService $apiKeyService,
    ) {}

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
            'scopes.*' => 'in:'.implode(',', array_keys(ApiClient::SCOPES)),
            'notes' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $created = $this->apiKeyService->createClient($data, $request->user()?->id);

        return redirect()->back()
            ->with('new_api_key', $created['plain_key'])
            ->with(
                'success',
                "API Key untuk {$created['client']->name} dibuat: {$created['plain_key']} — salin sekarang, key penuh hanya tampil sekali di sini."
            );
    }

    public function toggleActive(Request $request, ApiClient $apiClient): RedirectResponse
    {
        $updated = $this->apiKeyService->setActive($apiClient, ! $apiClient->is_active, $request->user()?->id);

        $status = $updated->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->back()->with('success', "API Key {$updated->name} {$status}.");
    }

    public function regenerate(Request $request, ApiClient $apiClient): RedirectResponse
    {
        $rotated = $this->apiKeyService->rotate($apiClient, $request->user()?->id);

        return redirect()->back()
            ->with('new_api_key', $rotated['plain_key'])
            ->with(
                'success',
                "API Key baru untuk {$rotated['client']->name}: {$rotated['plain_key']} — key lama langsung mati."
            );
    }

    public function destroy(ApiClient $apiClient): RedirectResponse
    {
        $apiClient->delete();

        return redirect()->back()->with('success', "API Key {$apiClient->name} dihapus permanen.");
    }
}
