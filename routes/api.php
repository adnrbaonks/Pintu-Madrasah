<?php

use App\Http\Controllers\Api\EmisController;
use App\Http\Controllers\Api\V1\AcademicYearApiController;
use App\Http\Controllers\Api\V1\ClassApiController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StaffApiController;
use App\Http\Controllers\Api\V1\StudentApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PINTU MADRASAH — API Routes
|--------------------------------------------------------------------------
*/

// ── Health Check ──────────────────────────────────────────────────────
Route::get('/health', fn () => response()->json(['status' => 'ok', 'app' => config('app.name')]));
Route::get('/v1/health', HealthController::class);

// ── EMIS Integration — Token Reception & Sync (Protected by Integration Token / Operator Session) ──
Route::prefix('emis')->middleware(['throttle:60,1', 'integration.token:emis_siswa'])->group(function () {
    Route::post('/token', [EmisController::class, 'receiveToken']);
    Route::post('/pull', [EmisController::class, 'triggerPull']);
    Route::post('/cancel-sync', [EmisController::class, 'cancelSync']);
    Route::get('/status', [EmisController::class, 'tokenStatus']);

    // Staging / Review Endpoints
    Route::get('/staging', [EmisController::class, 'stagingList']);
    Route::post('/commit', [EmisController::class, 'commitStaging']);
});

Route::prefix('emis-gtk')->middleware(['throttle:60,1', 'integration.token:emis_gtk'])->group(function () {
    Route::post('/token', [EmisController::class, 'receiveGtkToken']);
    Route::post('/import', [EmisController::class, 'importGtkData']);
    Route::post('/pull', [EmisController::class, 'triggerGtkPull']);
    Route::get('/staging', [EmisController::class, 'gtkStagingList']);
    Route::post('/commit', [EmisController::class, 'commitGtkStaging']);
    Route::get('/progress', [EmisController::class, 'gtkProgress']);
});

// Endpoint debugging HTML mentah — hanya aktif saat APP_DEBUG=true
if (config('app.debug')) {
    Route::prefix('emis-gtk')->middleware(['throttle:30,1', 'integration.token:emis_gtk'])->group(function () {
        Route::post('/import-raw', [EmisController::class, 'importGtkRaw']);
    });
}

// ── Open API Gateway — Protected by API Key ───────────────
// Konsumen (SIPSMAD, PTSP, E-Surat) wajib kirim X-API-KEY / ?api_key / Bearer.
Route::prefix('v1')->middleware(['throttle:120,1', 'api.key', 'api.idempotent'])->group(function () {
    Route::get('/students', [StudentApiController::class, 'index'])
        ->middleware('api.scope:students:read');
    Route::get('/students/by-id/{uuid}', [StudentApiController::class, 'showById'])
        ->middleware('api.scope:students:read');
    Route::get('/students/{nisn}', [StudentApiController::class, 'show'])
        ->middleware('api.scope:students:read');
    Route::get('/staff', [StaffApiController::class, 'index'])
        ->middleware('api.scope:staff:read');
    Route::get('/classes', [ClassApiController::class, 'index'])
        ->middleware('api.scope:classes:read');
    Route::get('/academic-years/active', [AcademicYearApiController::class, 'active'])
        ->middleware('api.scope:academic-years:read');
});
