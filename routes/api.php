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
    Route::post('/token', [EmisController::class, 'receiveToken'])->middleware('emis.ability:sync,credentials:update');
    Route::post('/pull', [EmisController::class, 'triggerPull'])->middleware('emis.ability:sync,sync:trigger');
    Route::post('/cancel-sync', [EmisController::class, 'cancelSync'])->middleware('emis.ability:sync,sync:cancel');
    Route::get('/status', [EmisController::class, 'tokenStatus'])->middleware('emis.ability:view,sync:read');

    // Staging / Review Endpoints
    Route::get('/staging', [EmisController::class, 'stagingList'])->middleware('emis.ability:view,staging:read');
    Route::post('/commit', [EmisController::class, 'commitStaging'])->middleware('emis.ability:sync,-');
    Route::post('/reconcile', [EmisController::class, 'reconcileStudents'])->middleware('emis.ability:reconcile,reconcile:run');
    Route::get('/reconciliation-batches', [EmisController::class, 'reconciliationBatches'])->middleware('emis.ability:view,reconcile:read');
    Route::get('/reconciliation-batches/{batch}/items', [EmisController::class, 'reconciliationBatchItems'])->middleware('emis.ability:view,reconcile:read');
    Route::get('/update-tasks', [EmisController::class, 'updateTasks'])->middleware('emis.ability:view,tasks:read');
    Route::patch('/update-tasks/{task}', [EmisController::class, 'updateTaskStatus'])->middleware('emis.ability:sync,-');
});

Route::prefix('emis-gtk')->middleware(['throttle:60,1', 'integration.token:emis_gtk'])->group(function () {
    Route::post('/token', [EmisController::class, 'receiveGtkToken'])->middleware('emis.ability:sync,credentials:update');
    Route::post('/import', [EmisController::class, 'importGtkData'])->middleware('emis.ability:sync,staging:write');
    Route::post('/pull', [EmisController::class, 'triggerGtkPull'])->middleware('emis.ability:sync,sync:trigger');
    Route::get('/staging', [EmisController::class, 'gtkStagingList'])->middleware('emis.ability:view,staging:read');
    Route::post('/commit', [EmisController::class, 'commitGtkStaging'])->middleware('emis.ability:sync,-');
    Route::post('/reconcile', [EmisController::class, 'reconcileGtk'])->middleware('emis.ability:reconcile,reconcile:run');
    Route::get('/reconciliation-batches', [EmisController::class, 'reconciliationBatches'])->middleware('emis.ability:view,reconcile:read');
    Route::get('/reconciliation-batches/{batch}/items', [EmisController::class, 'reconciliationBatchItems'])->middleware('emis.ability:view,reconcile:read');
    Route::get('/update-tasks', [EmisController::class, 'updateTasks'])->middleware('emis.ability:view,tasks:read');
    Route::patch('/update-tasks/{task}', [EmisController::class, 'updateTaskStatus'])->middleware('emis.ability:sync,-');
    Route::get('/status', [EmisController::class, 'gtkTokenStatus'])->middleware('emis.ability:view,sync:read');
    Route::get('/progress', [EmisController::class, 'gtkProgress'])->middleware('emis.ability:view,sync:read');
});

// Endpoint debugging HTML mentah — hanya aktif saat APP_DEBUG=true
if (config('app.debug')) {
    Route::prefix('emis-gtk')->middleware(['throttle:30,1', 'integration.token:emis_gtk'])->group(function () {
        Route::post('/import-raw', [EmisController::class, 'importGtkRaw'])->middleware('emis.ability:sync,staging:write');
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
