<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiDeprecation
{
    public function handle(
        Request $request,
        Closure $next,
        string $sunsetDate = 'Wed, 30 Jun 2027 23:59:59 GMT',
    ): Response {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', $sunsetDate);
        $response->headers->set('Link', '</api/documentation>; rel="deprecation"');

        return $response;
    }
}
