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

// ── EMIS Integration — Token Reception dari Bookmarklet ───────────────
// Endpoint ini dipanggil oleh bookmarklet di browser staf
// Tidak perlu auth karena token-nya yang menjadi credential
Route::prefix('emis')->group(function () {
    Route::post('/token',  [EmisController::class, 'receiveToken']);
    Route::post('/pull',   [EmisController::class, 'triggerPull']);
    Route::get('/status',  [EmisController::class, 'tokenStatus']);
});

Route::prefix('emis-gtk')->group(function () {
    Route::post('/token',  [EmisController::class, 'receiveGtkToken']);
});

// ── Open API Gateway (Sprint 5) — Protected by API Key ───────────────
// Akan diimplementasikan di Sprint 5
// Route::middleware('api.key')->prefix('v1')->group(function () {
//     Route::get('/students', [StudentApiController::class, 'index']);
//     Route::get('/students/{nisn}', [StudentApiController::class, 'show']);
//     Route::get('/staff', [StaffApiController::class, 'index']);
//     Route::get('/classes', [RombelApiController::class, 'index']);
//     Route::get('/academic-years/active', [AcademicYearApiController::class, 'active']);
// });
