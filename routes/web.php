<?php

use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FormCetakController;
use App\Http\Controllers\GtkController;
use App\Http\Controllers\LegerController;
use App\Http\Controllers\MutationController;
use App\Http\Controllers\PpdbController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\RombelController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDocumentController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| PINTU MADRASAH — Web Routes
|--------------------------------------------------------------------------
*/

// ── Verifikasi Dokumen Publik via QR (tanpa login) ────────────────────
Route::get('/v/{token}', [\App\Http\Controllers\VerifyController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('verify.show');
Route::get('/v/{token}/qr', [\App\Http\Controllers\VerifyController::class, 'qr'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('verify.qr');

// ── PPDB Publik (Guest Only) ───────────────────────────────────────────
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('/ppdb/daftar', [\App\Http\Controllers\PpdbController::class, 'publicForm'])->name('ppdb.public.form');
    Route::post('/ppdb/daftar', [\App\Http\Controllers\PpdbController::class, 'publicStore'])->name('ppdb.public.store');
});

// ── Autentikasi (Guest Only) ───────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
});

// ── Route Terproteksi (Authenticated) ─────────────────────────────────
Route::middleware('auth')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // ── Profil Pengguna — semua role ───────────────────────────────
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // ── Dashboard — semua role ─────────────────────────────────────
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // ── Manajemen Pengguna — admin only ────────────────────────────
    Route::middleware('checkrole:admin')->prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->name('reset-password');
        Route::post('/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('toggle-active');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
    });

    // ── Pengaturan — admin only ────────────────────────────────────
    Route::middleware('checkrole:admin')->prefix('pengaturan')->name('settings.')->group(function () {
        Route::get('/', [SettingController::class, 'index'])->name('index');
        Route::post('/', [SettingController::class, 'update'])->name('update');
        Route::post('/emis-sso-token', [SettingController::class, 'saveEmisSsoToken'])->name('emis-sso-token');
        Route::post('/academic-years', [SettingController::class, 'storeAcademicYear'])->name('academic-years.store');
        Route::post('/academic-years/{academicYear}/set-active', [SettingController::class, 'setActiveAcademicYear'])->name('academic-years.set-active');
        Route::post('/academic-years/{academicYear}/toggle-lock', [SettingController::class, 'toggleLockAcademicYear'])->name('academic-years.toggle-lock');
    });

    // ── API Clients (Gateway Keys) — admin only ───────────────────
    Route::middleware('checkrole:admin')->prefix('api-clients')->name('api-clients.')->group(function () {
        Route::get('/', [\App\Http\Controllers\ApiClientController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\ApiClientController::class, 'store'])->name('store');
        Route::post('/{apiClient}/toggle-active', [\App\Http\Controllers\ApiClientController::class, 'toggleActive'])->name('toggle-active');
        Route::post('/{apiClient}/regenerate', [\App\Http\Controllers\ApiClientController::class, 'regenerate'])->name('regenerate');
        Route::delete('/{apiClient}', [\App\Http\Controllers\ApiClientController::class, 'destroy'])->name('destroy');
    });
    // ── Webhooks (Event Subscription) — admin only ───────────────
    Route::middleware('checkrole:admin')->prefix('webhooks')->name('webhooks.')->group(function () {
        Route::get('/', [\App\Http\Controllers\WebhookSubscriptionController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\WebhookSubscriptionController::class, 'store'])->name('store');
        Route::post('/{webhook}/toggle-active', [\App\Http\Controllers\WebhookSubscriptionController::class, 'toggleActive'])->name('toggle-active');
        Route::post('/{webhook}/regenerate-secret', [\App\Http\Controllers\WebhookSubscriptionController::class, 'regenerateSecret'])->name('regenerate-secret');
        Route::post('/{webhook}/test', [\App\Http\Controllers\WebhookSubscriptionController::class, 'test'])->name('test');
        Route::delete('/{webhook}', [\App\Http\Controllers\WebhookSubscriptionController::class, 'destroy'])->name('destroy');
    });
    // ── BK (Pembinaan Karakter & Kedisiplinan) ────────────────────
    Route::middleware('checkrole:admin,staf,guru')->prefix('bk')->name('bk.')->group(function () {
        Route::get('/', [\App\Http\Controllers\BkController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\BkController::class, 'store'])->name('store');
        Route::put('/{bk}', [\App\Http\Controllers\BkController::class, 'update'])->name('update');
        Route::delete('/{bk}', [\App\Http\Controllers\BkController::class, 'destroy'])->name('destroy');
        Route::get('/{bk}/summon', [\App\Http\Controllers\BkController::class, 'printSummon'])->name('summon');
    });
    // ── Audit Logs — admin only ────────────────────────────────────
    Route::middleware('checkrole:admin')->prefix('audit-logs')->name('audit-logs.')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('index');
    });

    // ── Data Siswa, GTK, PPDB — admin & staf ──────────────────────
    Route::middleware('checkrole:admin,staf')->group(function () {

        // Siswa
        Route::prefix('students')->name('students.')->group(function () {
            Route::get('/', [StudentController::class, 'index'])->name('index');
            Route::get('/seat-layout', [StudentController::class, 'seatLayout'])->name('seat-layout');
            Route::get('/print-exam-cards', [StudentController::class, 'printExamCards'])->name('print-exam-cards');
            Route::get('/generate-nism', [StudentController::class, 'generateNismView'])->name('generate-nism-view');
            Route::post('/generate-nism', [StudentController::class, 'generateNism'])->name('generate-nism');
            Route::get('/{student}/edit', [StudentController::class, 'edit'])->name('edit');
            Route::put('/{student}', [StudentController::class, 'update'])->name('update');
            Route::post('/{student}/photo', [StudentController::class, 'uploadPhoto'])->name('photo.upload');
            Route::delete('/{student}/photo', [StudentController::class, 'deletePhoto'])->name('photo.delete');
            Route::get('/{student}', [StudentController::class, 'show'])->name('show');
            Route::post('/{student}/preview-emis-detail', [StudentController::class, 'previewEmisDetail'])->name('preview-emis-detail');
            Route::post('/{student}/commit-emis-detail', [StudentController::class, 'commitEmisDetail'])->name('commit-emis-detail');
            Route::post('/{student}/status', [StudentController::class, 'updateStatus'])->name('status.update');
            Route::get('/{student}/print-biodata', [StudentController::class, 'printBiodata'])->name('print-biodata');
            Route::post('/{student}/documents', [StudentDocumentController::class, 'upload'])->name('documents.upload');
        });
        Route::get('/export-students', [StudentController::class, 'exportData'])->name('students.export');
        Route::get('/export-students/pdf', [StudentController::class, 'exportPdf'])->name('students.export-pdf');

        // Mutasi
        Route::get('/siswa/mutasi', [MutationController::class, 'index'])->name('mutations.index');
        Route::post('/siswa/mutasi', [MutationController::class, 'store'])->name('mutations.store');
        Route::put('/siswa/mutasi/{mutation}', [MutationController::class, 'update'])->name('mutations.update');
        Route::delete('/siswa/mutasi/{mutation}', [MutationController::class, 'destroy'])->name('mutations.destroy');
        Route::post('/siswa/mutasi/{mutation}/upload', [MutationController::class, 'uploadDocument'])->name('mutations.upload');
        Route::post('/siswa/mutasi/sync', [MutationController::class, 'sync'])->name('mutations.sync');
        Route::get('/siswa/mutasi/progress', [MutationController::class, 'progress'])->name('mutations.progress');

        // Penempatan & Kenaikan Kelas
        Route::prefix('siswa/penempatan')->name('students.placement.')->group(function () {
            Route::get('/', [StudentController::class, 'placement'])->name('index');
            Route::post('/assign', [StudentController::class, 'assignRombel'])->name('assign');
        });
        Route::prefix('siswa/kenaikan-kelas')->name('promotions.')->group(function () {
            Route::get('/', [PromotionController::class, 'index'])->name('index');
            Route::get('/students', [PromotionController::class, 'getStudents'])->name('students');
            Route::post('/promote', [PromotionController::class, 'promote'])->name('promote');
            Route::post('/graduate', [PromotionController::class, 'graduate'])->name('graduate');
        });

        // Form Cetak
        Route::prefix('siswa/form-cetak')->name('students.form-cetak.')->group(function () {
            Route::get('/', [FormCetakController::class, 'index'])->name('index');
            Route::get('/data', [FormCetakController::class, 'getData'])->name('data');
        });

        // PPDB
        Route::redirect('/siswa/ppdb', '/ppdb');
        Route::prefix('ppdb')->name('ppdb.')->group(function () {
            Route::get('/', [PpdbController::class, 'index'])->name('index');
            Route::post('/periods', [PpdbController::class, 'storePeriod'])->name('periods.store');
            Route::put('/periods/{period}/toggle-active', [PpdbController::class, 'togglePeriodActive'])->name('periods.toggle-active');
            Route::get('/registrations', [PpdbController::class, 'registrations'])->name('registrations');
            Route::post('/registrations/{registration}/scan', [PpdbController::class, 'scanDocument'])->name('registrations.scan');
            Route::get('/registrations/create', [PpdbController::class, 'create'])->name('create');
            Route::post('/registrations', [PpdbController::class, 'storeRegistration'])->name('store');
            Route::get('/registrations/{registration}', [PpdbController::class, 'show'])->name('show');
            Route::get('/registrations/{registration}/edit', [PpdbController::class, 'edit'])->name('edit');
            Route::put('/registrations/{registration}', [PpdbController::class, 'updateRegistration'])->name('update');
            Route::delete('/registrations/{registration}', [PpdbController::class, 'destroyRegistration'])->name('destroy');
            Route::put('/registrations/{registration}/status', [PpdbController::class, 'updateStatus'])->name('update-status');
        });

        // GTK
        Route::prefix('gtk')->name('gtk.')->group(function () {
            Route::get('/', [GtkController::class, 'index'])->name('index');
            Route::get('/tpg', [\App\Http\Controllers\StatistikController::class, 'tpg'])->name('tpg');
            Route::put('/{staff}/tpg-status', [\App\Http\Controllers\StatistikController::class, 'updateTpgStatus'])->name('tpg.update');
        });

        // Statistik
        Route::get('/statistik/demografi', [\App\Http\Controllers\StatistikController::class, 'demografi'])->name('statistik.demografi');

        // Persuratan
        Route::prefix('persuratan')->name('persuratan.')->group(function () {
            Route::get('/', [\App\Http\Controllers\LetterController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\LetterController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\LetterController::class, 'store'])->name('store');
            Route::get('/{letter}', [\App\Http\Controllers\LetterController::class, 'show'])->name('show');
            Route::get('/{letter}/edit', [\App\Http\Controllers\LetterController::class, 'edit'])->name('edit');
            Route::put('/{letter}', [\App\Http\Controllers\LetterController::class, 'update'])->name('update');
            Route::delete('/{letter}', [\App\Http\Controllers\LetterController::class, 'destroy'])->name('destroy');
        });

        // Integrasi EMIS
        Route::get('/emis/siswa', fn () => Inertia::render('Emis/Siswa'))->name('emis.siswa');
        Route::get('/emis/gtk', [\App\Http\Controllers\EmisGtkController::class, 'index'])->name('emis.gtk');
        Route::post('/emis/gtk/sync', [\App\Http\Controllers\EmisGtkController::class, 'sync'])->name('emis.gtk.sync');
        Route::get('/emis/gtk/sync-status', [\App\Http\Controllers\EmisGtkController::class, 'syncStatus'])->name('emis.gtk.sync-status');

        // Leger Nilai
        Route::get('/kelas/leger', [LegerController::class, 'index'])->name('leger.index');
        Route::get('/kelas/leger/export', [LegerController::class, 'export'])->name('leger.export');
        Route::post('/kelas/leger/grade', [LegerController::class, 'upsertGrade'])->name('leger.grade.upsert');

        // Rombel
        Route::prefix('rombel')->name('rombel.')->group(function () {
            Route::get('/', [RombelController::class, 'index'])->name('index');
            Route::post('/', [RombelController::class, 'store'])->name('store');
            Route::get('/{rombel}', [RombelController::class, 'show'])->name('show');
            Route::put('/{rombel}', [RombelController::class, 'update'])->name('update');
            Route::delete('/{rombel}', [RombelController::class, 'destroy'])->name('destroy');
        });

        // Format Cetak
        Route::prefix('cetak-format')->name('print.')->group(function () {
            Route::get('/', [PrintController::class, 'index'])->name('index');
            Route::get('/daftar-siswa', [PrintController::class, 'daftarSiswa'])->name('daftar-siswa');
            Route::get('/daftar-kelas', [PrintController::class, 'daftarKelas'])->name('daftar-kelas');
            Route::get('/absen-harian', [PrintController::class, 'absenHarian'])->name('absen-harian');
        });

        // ── Archives & Document Retry — admin & staf only ──────────
        Route::get('/archives/{type}/{filename}', [ArchiveController::class, 'show'])->name('archives.show');
        Route::post('/documents/{type}/{id}/retry', [ArchiveController::class, 'retry'])->name('documents.retry');

    });
});
