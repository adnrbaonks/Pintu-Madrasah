<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = Setting::get('api_key', env('API_GATEWAY_KEY', ''));

        if (empty($configured)) {
            return response()->json([
                'success' => false,
                'message' => 'API Gateway belum dikonfigurasi. Atur API Key di Pengaturan.',
            ], 503);
        }

        $provided = $request->header('X-API-KEY')
            ?: $request->query('api_key')
            ?: (str_starts_with((string) $request->header('Authorization'), 'Bearer ')
                ? trim(substr((string) $request->header('Authorization'), 7))
                : null);

        if (! $provided || ! hash_equals((string) $configured, (string) $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'API Key tidak valid.',
            ], 401);
        }

        return $next($request);
    }
}
