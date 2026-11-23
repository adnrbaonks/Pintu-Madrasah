<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware untuk memproteksi route berdasarkan Spatie role.
 *
 * Kolom `users.role` tetap sebagai sumber kebenaran bisnis (sinkron ke
 * Spatie via User::created/updated dan rbac:backfill), namun otorisasi
 * rute hanya mengenal Spatie sebagai sumber tunggal.
 *
 * Penggunaan di route: ->middleware('checkrole:admin')
 *                 atau ->middleware('checkrole:admin,staf')
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $hasRole = $user && $user->hasAnyRole($roles);

        if (! $hasRole) {
            if ($request->inertia()) {
                return redirect()->route('dashboard')
                    ->with('error', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
            }

            abort(403, 'Akses ditolak.');
        }

        return $next($request);
    }
}
