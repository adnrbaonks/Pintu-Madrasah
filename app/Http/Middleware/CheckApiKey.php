<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiKey
{
    public function __construct(
        private ApiKeyService $apiKeyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->attributes->get('request_id');

        $provided = $request->header('X-API-KEY')
            ?: (str_starts_with((string) $request->header('Authorization'), 'Bearer ')
                ? trim(substr((string) $request->header('Authorization'), 7))
                : null);

        if (! $provided) {
            return response()->json([
                'success' => false,
                'message' => 'API Key wajib dikirim via header X-API-KEY.',
                'error' => ['code' => 'MISSING_API_KEY'],
                'request_id' => $requestId,
            ], 401);
        }

        // ── Prioritas 1: kunci per-klien di database (hash SHA-256 + dual-read transisi) ──
        $client = $this->apiKeyService->findMatchingClient((string) $provided);

        if ($client) {
            if ($client->isRevoked()) {
                return response()->json([
                    'success' => false,
                    'message' => 'API Key telah dinonaktifkan. Hubungi operator TU.',
                    'error' => ['code' => 'API_KEY_REVOKED'],
                    'request_id' => $requestId,
                ], 403);
            }

            if ($client->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Masa berlaku API Key telah kedaluwarsa. Silakan lakukan rotasi key.',
                    'error' => ['code' => 'API_KEY_EXPIRED'],
                    'request_id' => $requestId,
                ], 403);
            }

            // Throttle update last_used_at (maks 1x per menit)
            if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
                $client->update(['last_used_at' => now()]);
            }

            $request->attributes->set('api_client', $client);
            $request->attributes->set('api_scopes', $client->scopes ?? []);

            return $next($request);
        }

        // ── Fallback: kunci tunggal legacy (Setting / env) dibatasi scope non-PII dasar ──
        $legacy = Setting::get('api_key', env('API_GATEWAY_KEY', ''));
        if ($legacy && hash_equals((string) $legacy, (string) $provided)) {
            $request->attributes->set('api_client', null);
            $request->attributes->set('api_scopes', ['students:read', 'staff:read', 'classes:read', 'academic-years:read']);

            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'API Key tidak valid.',
            'error' => ['code' => 'INVALID_API_KEY'],
            'request_id' => $requestId,
        ], 401);
    }
}
