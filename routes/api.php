<?php

use App\Http\Controllers\Api\EmisController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PINTU MADRASAH — API Routes
|--------------------------------------------------------------------------
*/

// ── Health Check ──────────────────────────────────────────────────────
Route::get('/health', fn() => response()->json(['status' => 'ok', 'app' => config('app.name')]));

// ── Global Search (Command Palette) ───────────────────────────────────
Route::middleware('auth')->get('/search', \App\Http\Controllers\Api\SearchController::class);

// ── EMIS Integration — Token Reception dari Bookmarklet ───────────────
// Endpoint ini dipanggil oleh bookmarklet di browser staf
// Tidak perlu auth karena token-nya yang menjadi credential
Route::prefix('emis')->group(function () {
    Route::post('/token',  [EmisController::class, 'receiveToken']);
    Route::post('/pull',   [EmisController::class, 'triggerPull']);
    Route::post('/cancel-sync', [EmisController::class, 'cancelSync']);
    Route::get('/status',  [EmisController::class, 'tokenStatus']);
    
    // Staging / Review Endpoints
    Route::get('/staging', [EmisController::class, 'stagingList']);
    Route::post('/commit', [EmisController::class, 'commitStaging']);
});

Route::prefix('emis-gtk')->group(function () {
    Route::post('/token',  [EmisController::class, 'receiveGtkToken']);
    Route::post('/import', [EmisController::class, 'importGtkData']); // Terima data langsung dari bookmarklet
    Route::post('/import-raw', [EmisController::class, 'importGtkRaw']); // Debugging HTML
    Route::post('/pull',   [EmisController::class, 'triggerGtkPull']);
    Route::get('/staging', [EmisController::class, 'gtkStagingList']);
    Route::post('/commit', [EmisController::class, 'commitGtkStaging']);
    Route::get('/progress',[EmisController::class, 'gtkProgress']);
});

// ── Open API Gateway (Sprint 5) ───────────────
// Saat production, berikan middleware custom 'api.key' untuk melindungi
Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    Route::get('/students', [\App\Http\Controllers\Api\V1\StudentApiController::class, 'index']);
    Route::get('/students/{nisn}', [\App\Http\Controllers\Api\V1\StudentApiController::class, 'show']);
    Route::get('/staff', [\App\Http\Controllers\Api\V1\StaffApiController::class, 'index']);
    Route::get('/classes', [\App\Http\Controllers\Api\V1\ClassApiController::class, 'index']);
    Route::get('/academic-years/active', [\App\Http\Controllers\Api\V1\AcademicYearApiController::class, 'active']);
});
