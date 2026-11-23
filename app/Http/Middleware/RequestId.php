<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        $requestId = (is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming))
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $request->headers->set('X-Request-Id', $requestId);

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
