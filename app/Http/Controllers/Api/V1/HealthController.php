<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/health',
        tags: ['System'],
        summary: 'Status Kesehatan Sistem & Dependensi (V1)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sistem beroperasi normal',
            ),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }

        $cacheOk = false;
        try {
            Cache::put('health:check', 'ok', 10);
            $cacheOk = Cache::get('health:check') === 'ok';
        } catch (\Throwable) {
            $cacheOk = false;
        }

        return response()->json([
            'status' => ($dbOk && $cacheOk) ? 'ok' : 'degraded',
            'version' => 'v1',
            'app' => config('app.name'),
            'checks' => [
                'database' => $dbOk ? 'up' : 'down',
                'cache' => $cacheOk ? 'up' : 'down',
            ],
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
