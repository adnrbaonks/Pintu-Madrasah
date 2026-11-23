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
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        $cacheKey = 'idempotency:'.hash('sha256', $request->path().'|'.$key);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return response()->json($cached['body'], $cached['status'], [
                'X-Idempotent-Replay' => 'true',
            ]);
        }

        $response = $next($request);

        if ($response->isSuccessful() && str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $response->getContent(), true);
            if (is_array($decoded)) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => $decoded,
                ], now()->addHours(24));
            }
        }

        return $response;
    }
}
