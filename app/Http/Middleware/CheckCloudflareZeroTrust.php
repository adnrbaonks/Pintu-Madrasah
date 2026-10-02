<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCloudflareZeroTrust
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Pengecekan Header dari Cloudflare Access (CF-Access-JWT-Assertion)
        // Jika aplikasi berada di mode production dan header ini tidak ada, blokir akses.
        
        if (app()->environment('production')) {
            $cfHeader = $request->header('CF-Access-JWT-Assertion');
            
            if (!$cfHeader) {
                abort(403, 'Akses Ditolak: Permintaan harus melalui Cloudflare Zero Trust.');
            }
            
            // Opsional: Validasi token JWT dari Cloudflare (memerlukan aud & public keys dari CF)
        }

        return $next($request);
    }
}
