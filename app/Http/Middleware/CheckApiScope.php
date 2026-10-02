<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiScope
{
    /**
     * Pastikan API client punya scope yang disyaratkan.
     * Dipakai: ->middleware('api.scope:students:read')
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $scopes = $request->attributes->get('api_scopes', []);

        if (! in_array($scope, $scopes, true)) {
            return response()->json([
                'success' => false,
                'message' => "Scope '{$scope}' tidak dimiliki API Key ini.",
            ], 403);
        }

        return $next($request);
    }
}
