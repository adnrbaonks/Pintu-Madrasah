<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware untuk memproteksi route berdasarkan kolom `role` di tabel users.
 *
 * Penggunaan di route: ->middleware('checkrole:admin')
 *                 atau ->middleware('checkrole:admin,staf')
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Cek apakah user punya salah satu role (kolom role atau Spatie role)
        $hasRole = $user && (
            in_array($user->role, $roles, true) ||
            $user->hasAnyRole($roles)
        );

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
