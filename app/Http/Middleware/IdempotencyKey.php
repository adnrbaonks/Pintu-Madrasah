<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        $key = trim($key);
        $clientNamespace = $this->resolveClientNamespace($request);
        $method = strtoupper($request->method());
        $endpoint = '/'.ltrim($request->path(), '/');

        $fingerprint = $this->calculateFingerprint($request, $method, $endpoint);
        $cacheKey = "idempotency:{$clientNamespace}:".hash('sha256', "{$method}|{$endpoint}|{$key}");

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $cachedFingerprint = $cached['fingerprint'] ?? null;

            // Tolak jika key sama digunakan untuk payload atau parameter berbeda
            if (! is_string($cachedFingerprint) || ! hash_equals($cachedFingerprint, $fingerprint)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Idempotency-Key telah digunakan untuk request dengan payload atau parameter yang berbeda.',
                    'error' => [
                        'code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
                        'idempotency_key' => $key,
                    ],
                    'request_id' => $request->attributes->get('request_id'),
                ], 422);
            }

            return response()->json(
                $cached['body'] ?? [],
                $cached['status'] ?? 200,
                array_merge($cached['headers'] ?? [], [
                    'X-Idempotent-Replay' => 'true',
                ])
            );
        }

        $response = $next($request);

        if ($response->isSuccessful() && str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $response->getContent(), true);
            if (is_array($decoded)) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $decoded,
                    'headers' => array_filter([
                        'Content-Type' => $response->headers->get('Content-Type'),
                    ]),
                    'created_at' => now()->timestamp,
                ], now()->addHours(24));
            }
        }

        return $response;
    }

    /**
     * Resolusi namespace unik per API client untuk mencegah kebocoran response cache antar-klien.
     */
    private function resolveClientNamespace(Request $request): string
    {
        $client = $request->attributes->get('api_client');
        if ($client && isset($client->id)) {
            return 'client:'.$client->id;
        }

        $user = $request->user();
        if ($user && isset($user->id)) {
            return 'user:'.$user->id;
        }

        $providedApiKey = $request->header('X-API-KEY')
            ?: (str_starts_with((string) $request->header('Authorization'), 'Bearer ')
                ? trim(substr((string) $request->header('Authorization'), 7))
                : null);

        if ($providedApiKey) {
            return 'key:'.hash('sha256', (string) $providedApiKey);
        }

        return 'ip:'.$request->ip();
    }

    /**
     * Bentuk request fingerprint mencakup method, endpoint, payload, dan query parameter terurut.
     */
    private function calculateFingerprint(Request $request, string $method, string $endpoint): string
    {
        $query = $request->query();
        ksort($query);

        $payload = (string) $request->getContent();

        return hash('sha256', "{$method}|{$endpoint}|{$payload}|".json_encode($query));
    }
}
