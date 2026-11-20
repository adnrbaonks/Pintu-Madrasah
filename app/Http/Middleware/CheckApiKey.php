<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-API-KEY')
            ?: $request->query('api_key')
            ?: (str_starts_with((string) $request->header('Authorization'), 'Bearer ')
                ? trim(substr((string) $request->header('Authorization'), 7))
                : null);

        if (! $provided) {
            return response()->json([
                'success' => false,
                'message' => 'API Key wajib dikirim via header X-API-KEY.',
            ], 401);
        }

        // ── Prioritas 1: kunci per-klien di database ──
        $client = ApiClient::where('api_key', $provided)
            ->where('is_active', true)
            ->first();

        if ($client) {
            $client->update(['last_used_at' => now()]);
            $request->attributes->set('api_client', $client);
            $request->attributes->set('api_scopes', $client->scopes ?? []);

            return $next($request);
        }

        // ── Fallback: kunci tunggal legacy (Setting / env) = akses penuh ──
        $legacy = Setting::get('api_key', env('API_GATEWAY_KEY', ''));
        if ($legacy && hash_equals((string) $legacy, (string) $provided)) {
            $request->attributes->set('api_client', null);
            $request->attributes->set('api_scopes', array_keys(ApiClient::SCOPES));

            return $next($request);
        }

        // Nonaktifkan key yang dicabut / tidak dikenal tanpa membocorkan daftar
        $revoked = ApiClient::where('api_key', $provided)->first();
        if ($revoked && ! $revoked->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'API Key telah dinonaktifkan. Hubungi operator TU.',
            ], 403);
        }

        return response()->json([
            'success' => false,
            'message' => 'API Key tidak valid.',
        ], 401);
    }
}
