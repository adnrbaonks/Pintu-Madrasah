<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Least-privilege untuk endpoint EMIS pada autentikasi sesi.
 * Ability: view (lihat), reconcile (rekonsiliasi), sync (pull/cancel/token/import/commit/update task).
 * Token integrasi sudah dibatasi oleh purpose dan aturan khusus di controller.
 */
class EnsureEmisAbility
{
    private const ABILITY_PERMISSIONS = [
        'view' => 'view_emis',
        'reconcile' => 'reconcile_emis',
        'sync' => 'sync_emis',
    ];

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        if ($request->attributes->get('integration_auth_method') === 'token') {
            return $next($request);
        }

        $permission = self::ABILITY_PERMISSIONS[$ability] ?? null;
        $user = $request->user();

        if ($permission === null || ! $user || ! $user->can($permission)) {
            return response()->json([
                'success' => false,
                'message' => "Anda tidak memiliki izin ({$permission}) untuk aksi EMIS ini.",
                'error' => ['code' => 'FORBIDDEN_PERMISSION'],
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        return $next($request);
    }
}
