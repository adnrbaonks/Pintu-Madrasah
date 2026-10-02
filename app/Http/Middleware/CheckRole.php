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

        if (! $user || ! in_array($user->role, $roles, true)) {
            if ($request->inertia()) {
                return redirect()->route('dashboard')
                    ->with('error', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
            }

            abort(403, 'Akses ditolak.');
        }

        return $next($request);
    }
}
