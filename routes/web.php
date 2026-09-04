<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| PINTU MADRASAH — Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return Inertia::render('Dashboard', [
        'appName'      => config('app.app_name', 'PINTU MADRASAH'),
        'madrasahName' => env('MADRASAH_NAME', 'MTs Negeri Contoh'),
        'stats'        => [
            'totalSiswa'   => \App\Models\Student::where('status', 'active')->count(),
            'totalGTK'     => \App\Models\Staff::where('is_active', true)->count(),
            'totalRombel'  => \App\Models\Rombel::count(),
            'tahunAjaran'  => \App\Models\AcademicYear::active()?->full_label ?? 'Belum ada tahun ajaran aktif',
        ],
    ]);
})->name('dashboard');

Route::prefix('students')->name('students.')->group(function () {
    Route::get('/', [\App\Http\Controllers\StudentController::class, 'index'])->name('index');
    Route::get('/seat-layout', [\App\Http\Controllers\StudentController::class, 'seatLayout'])->name('seat-layout');
    Route::get('/print-exam-cards', [\App\Http\Controllers\StudentController::class, 'printExamCards'])->name('print-exam-cards');
    Route::get('/{student}', [\App\Http\Controllers\StudentController::class, 'show'])->name('show');
    Route::get('/{student}/print-biodata', [\App\Http\Controllers\StudentController::class, 'printBiodata'])->name('print-biodata');
});
