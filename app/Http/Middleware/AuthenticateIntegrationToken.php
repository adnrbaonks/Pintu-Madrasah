<?php

namespace App\Http\Middleware;

use App\Services\IntegrationTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegrationToken
{
    public function __construct(
        private IntegrationTokenService $tokenService,
    ) {}

    public function handle(Request $request, Closure $next, ?string $purpose = null): Response
    {
        $provided = $request->header('X-Integration-Token')
            ?: $request->input('integration_token')
            ?: (str_starts_with((string) $request->header('Authorization'), 'Bearer ')
                ? trim(substr((string) $request->header('Authorization'), 7))
                : null);

        // 1. Jika token integrasi dikirim, wajib valid (dan sesuai purpose bila ditentukan)
        if (is_string($provided) && $provided !== '') {
            $token = $this->tokenService->verify($provided, $purpose);

            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token integrasi EMIS tidak valid, kedaluwarsa, atau telah dicabut.',
                    'error' => ['code' => 'UNAUTHORIZED_INTEGRATION_TOKEN'],
                    'request_id' => $request->attributes->get('request_id'),
                ], 401);
            }

            $request->attributes->set('integration_token', $token);
            $request->attributes->set('integration_purpose', $purpose ?? $token->purpose);
            $request->attributes->set('integration_auth_method', 'token');

            return $next($request);
        }

        // 2. Fallback transisi same-origin session: user operator/admin yang sudah login di aplikasi web
        $user = $request->user() ?: Auth::guard('web')->user();
        if ($user) {
            if ($user->can('view_emis') || $user->can('reconcile_emis') || $user->can('sync_emis')) {
                $request->attributes->set('integration_purpose', $purpose);
                $request->attributes->set('integration_auth_method', 'session');

                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin untuk mengakses endpoint integrasi EMIS.',
                'error' => ['code' => 'FORBIDDEN_PERMISSION'],
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        return response()->json([
            'success' => false,
            'message' => 'Endpoint integrasi EMIS memerlukan token integrasi aktif atau sesi operator yang sah.',
            'error' => ['code' => 'UNAUTHORIZED_INTEGRATION_TOKEN'],
            'request_id' => $request->attributes->get('request_id'),
        ], 401);
    }
}
