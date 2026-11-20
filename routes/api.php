<?php

use App\Http\Controllers\Api\EmisController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\V1\AcademicYearApiController;
use App\Http\Controllers\Api\V1\ClassApiController;
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

// ── Global Search (Command Palette) ───────────────────────────────────
Route::middleware('auth')->get('/search', SearchController::class);

// ── EMIS Integration — Token Reception dari Bookmarklet ───────────────
// Endpoint ini dipanggil oleh bookmarklet di browser staf
// Tidak perlu auth karena token-nya yang menjadi credential,
// tapi dibatasi throttle agar tidak disalahgunakan dari internet.
Route::prefix('emis')->middleware('throttle:60,1')->group(function () {
    Route::post('/token', [EmisController::class, 'receiveToken']);
    Route::post('/pull', [EmisController::class, 'triggerPull']);
    Route::post('/cancel-sync', [EmisController::class, 'cancelSync']);
    Route::get('/status', [EmisController::class, 'tokenStatus']);

    // Staging / Review Endpoints
    Route::get('/staging', [EmisController::class, 'stagingList']);
    Route::post('/commit', [EmisController::class, 'commitStaging']);
});

Route::prefix('emis-gtk')->middleware('throttle:60,1')->group(function () {
    Route::post('/token', [EmisController::class, 'receiveGtkToken']);
    Route::post('/import', [EmisController::class, 'importGtkData']); // Terima data langsung dari bookmarklet
    Route::post('/pull', [EmisController::class, 'triggerGtkPull']);
    Route::get('/staging', [EmisController::class, 'gtkStagingList']);
    Route::post('/commit', [EmisController::class, 'commitGtkStaging']);
    Route::get('/progress', [EmisController::class, 'gtkProgress']);
});

// Endpoint debugging HTML mentah — hanya aktif saat APP_DEBUG=true
if (config('app.debug')) {
    Route::prefix('emis-gtk')->middleware('throttle:30,1')->group(function () {
        Route::post('/import-raw', [EmisController::class, 'importGtkRaw']);
    });
}

// ── Open API Gateway — Protected by API Key ───────────────
// Konsumen (SIPSMAD, PTSP, E-Surat) wajib kirim X-API-KEY / ?api_key / Bearer.
// Key dikelola di Pengaturan (Setting api_key) atau env API_GATEWAY_KEY.
Route::prefix('v1')->middleware(['throttle:120,1', 'api.key'])->group(function () {
    Route::get('/students', [StudentApiController::class, 'index'])
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
