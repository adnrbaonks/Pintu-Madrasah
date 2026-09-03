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
        'appName'      => config('app.name', 'PINTU MADRASAH'),
        'madrasahName' => env('MADRASAH_NAME', 'MTs Negeri Contoh'),
        'stats'        => [
            'totalSiswa'   => 0,
            'totalGTK'     => 0,
            'totalRombel'  => 0,
            'tahunAjaran'  => '2026/2027 Ganjil',
        ],
    ]);
})->name('dashboard');
