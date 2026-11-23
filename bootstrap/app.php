<?php

use App\Http\Middleware\ApiDeprecation;
use App\Http\Middleware\AuthenticateIntegrationToken;
use App\Http\Middleware\CheckApiKey;
use App\Http\Middleware\CheckApiScope;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\ForceJson;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\RequestId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->api(prepend: [
            ForceJson::class,
            RequestId::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'checkrole' => CheckRole::class,
            'api.key' => CheckApiKey::class,
            'api.scope' => CheckApiScope::class,
            'api.deprecation' => ApiDeprecation::class,
            'api.idempotent' => IdempotencyKey::class,
            'integration.token' => AuthenticateIntegrationToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                    'error' => ['code' => 'VALIDATION_ERROR'],
                    'request_id' => $request->attributes->get('request_id'),
                ], $e->status);
            }
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data atau endpoint yang diminta tidak ditemukan.',
                    'error' => ['code' => 'RESOURCE_NOT_FOUND'],
                    'request_id' => $request->attributes->get('request_id'),
                ], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batas jumlah permintaan (rate limit) terlampaui. Silakan coba lagi beberapa saat.',
                    'error' => ['code' => 'RATE_LIMIT_EXCEEDED'],
                    'request_id' => $request->attributes->get('request_id'),
                ], 429, $e->getHeaders());
            }
        });
    })->create();
