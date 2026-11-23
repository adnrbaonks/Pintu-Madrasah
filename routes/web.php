<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\ApiClientController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmisGtkController;
use App\Http\Controllers\FormCetakController;
use App\Http\Controllers\IntegrationTokenController;
use App\Http\Controllers\LegerController;
use App\Http\Controllers\LetterController;
use App\Http\Controllers\MutationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PpdbController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RombelController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StaffApprovalController;
use App\Http\Controllers\StaffChangeRequestController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffSelfController;
use App\Http\Controllers\StaffVerificationController;
use App\Http\Controllers\StatistikController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDocumentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerifyController;
use App\Http\Controllers\WebhookSubscriptionController;
use App\Models\IntegrationToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| PINTU MADRASAH — Web Routes
|--------------------------------------------------------------------------
*/

// ── Verifikasi Dokumen Publik via QR (tanpa login) ────────────────────
Route::get('/v/{token}', [VerifyController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('verify.show');
Route::get('/v/{token}/qr', [VerifyController::class, 'qr'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('verify.qr');

// ── PPDB Publik (Guest Only) ───────────────────────────────────────────
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('/ppdb/daftar', [PpdbController::class, 'publicForm'])->name('ppdb.public.form');
    Route::post('/ppdb/daftar', [PpdbController::class, 'publicStore'])->name('ppdb.public.store');
});

// ── Autentikasi (Guest Only) ───────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
});

// ── Route Terproteksi (Authenticated) ─────────────────────────────────
Route::middleware('auth')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // ── Global Search (Command Palette) ────────────────────────────
    // URL tetap /api/search agar frontend tidak berubah, tetapi route
    // dijalankan pada middleware web agar sesi login tersedia.
    Route::get('/api/search', SearchController::class)->name('search');

    // ── Profil Pengguna — semua role ───────────────────────────────
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // ── Layanan Mandiri GTK (Self-Service & Pengajuan Perubahan Data - WP5) ──
    Route::prefix('profil-gtk')->name('staff-self.')->group(function () {
        Route::get('/', [StaffSelfController::class, 'profile'])->name('profile');
        Route::get('/dokumen', [StaffSelfController::class, 'documents'])->name('documents.index');
        Route::post('/dokumen', [StaffSelfController::class, 'uploadDocument'])->name('documents.store');
        Route::delete('/dokumen/{document}', [StaffSelfController::class, 'destroyDocument'])->name('documents.destroy');

        Route::prefix('pengajuan')->name('requests.')->group(function () {
            Route::get('/', [StaffChangeRequestController::class, 'index'])->name('index');
            Route::get('/create', [StaffChangeRequestController::class, 'create'])->name('create');
            Route::post('/', [StaffChangeRequestController::class, 'store'])->name('store');
            Route::get('/{changeRequest}', [StaffChangeRequestController::class, 'show'])->name('show');
            Route::put('/{changeRequest}', [StaffChangeRequestController::class, 'update'])->name('update');
            Route::post('/{changeRequest}/submit', [StaffChangeRequestController::class, 'submit'])->name('submit');
            Route::post('/{changeRequest}/cancel', [StaffChangeRequestController::class, 'cancel'])->name('cancel');
        });
    });

    // ── Verifikasi Perubahan Data GTK (Tahap 1: Tata Usaha - WP5) ──
    Route::middleware('checkrole:admin,kaur_tu,staf,operator')->prefix('verifikasi-gtk')->name('staff-verification.')->group(function () {
        Route::get('/', [StaffVerificationController::class, 'index'])->name('index');
        Route::get('/{changeRequest}', [StaffVerificationController::class, 'show'])->name('show');
        Route::post('/{changeRequest}/verify', [StaffVerificationController::class, 'verify'])->name('verify');
        Route::post('/{changeRequest}/revision', [StaffVerificationController::class, 'revision'])->name('revision');
        Route::post('/{changeRequest}/reject', [StaffVerificationController::class, 'reject'])->name('reject');
    });

    // ── Persetujuan Perubahan Data GTK (Tahap 2: Kepala Madrasah - WP5) ──
    Route::middleware('checkrole:admin,kepala_madrasah')->prefix('persetujuan-gtk')->name('staff-approval.')->group(function () {
        Route::get('/', [StaffApprovalController::class, 'index'])->name('index');
        Route::get('/{changeRequest}', [StaffApprovalController::class, 'show'])->name('show');
        Route::post('/{changeRequest}/approve', [StaffApprovalController::class, 'approve'])->name('approve');
        Route::post('/{changeRequest}/revision', [StaffApprovalController::class, 'revision'])->name('revision');
        Route::post('/{changeRequest}/reject', [StaffApprovalController::class, 'reject'])->name('reject');
    });

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
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
    Route::middleware('checkrole:admin,kepala_madrasah,kaur_tu,operator')->get('/pengaturan/kamus-data', [SettingController::class, 'dataDictionary'])->name('settings.data-dictionary');

    // ── API Clients (Gateway Keys) — admin only ───────────────────
    Route::middleware('checkrole:admin')->prefix('api-clients')->name('api-clients.')->group(function () {
        Route::get('/', [ApiClientController::class, 'index'])->name('index');
        Route::post('/', [ApiClientController::class, 'store'])->name('store');
        Route::post('/{apiClient}/toggle-active', [ApiClientController::class, 'toggleActive'])->name('toggle-active');
        Route::post('/{apiClient}/regenerate', [ApiClientController::class, 'regenerate'])->name('regenerate');
        Route::delete('/{apiClient}', [ApiClientController::class, 'destroy'])->name('destroy');
    });
    // ── Webhooks (Event Subscription) — admin only ───────────────
    Route::middleware('checkrole:admin')->prefix('webhooks')->name('webhooks.')->group(function () {
        Route::get('/', [WebhookSubscriptionController::class, 'index'])->name('index');
        Route::post('/', [WebhookSubscriptionController::class, 'store'])->name('store');
        Route::post('/deliveries/{delivery}/retry', [WebhookSubscriptionController::class, 'retryDelivery'])->name('deliveries.retry');
        Route::post('/{webhook}/toggle-active', [WebhookSubscriptionController::class, 'toggleActive'])->name('toggle-active');
        Route::post('/{webhook}/regenerate-secret', [WebhookSubscriptionController::class, 'regenerateSecret'])->name('regenerate-secret');
        Route::post('/{webhook}/test', [WebhookSubscriptionController::class, 'test'])->name('test');
        Route::delete('/{webhook}', [WebhookSubscriptionController::class, 'destroy'])->name('destroy');
    });
    // ── BK (Pembinaan Karakter & Kedisiplinan) ────────────────────
    Route::middleware('checkrole:admin,kepala_madrasah,kaur_tu,staf,operator,guru')->prefix('bk')->name('bk.')->group(function () {
        Route::get('/', [BkController::class, 'index'])->name('index');
        Route::post('/', [BkController::class, 'store'])->name('store');
        Route::put('/{bk}', [BkController::class, 'update'])->name('update');
        Route::delete('/{bk}', [BkController::class, 'destroy'])->name('destroy');
        Route::get('/{bk}/summon', [BkController::class, 'printSummon'])->name('summon');
    });

    // ── Audit Logs — admin only ────────────────────────────────────
    Route::middleware('checkrole:admin,kepala_madrasah')->prefix('audit-logs')->name('audit-logs.')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('index');
    });

    // ── Data Siswa, GTK, PPDB — staf, operator, kaur TU, kamad, admin ──
    Route::middleware('checkrole:admin,kepala_madrasah,kaur_tu,staf,operator')->group(function () {

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

        // GTK (StaffController kanonik)
        Route::prefix('gtk')->name('gtk.')->group(function () {
            Route::get('/', [StaffController::class, 'index'])->name('index');
            Route::get('/tpg', [StatistikController::class, 'tpg'])->name('tpg');
            Route::put('/{staff}/tpg-status', [StatistikController::class, 'updateTpgStatus'])
                ->middleware('checkrole:admin,kepala_madrasah,kaur_tu,operator')
                ->name('tpg.update');
            Route::get('/{staff}', [StaffController::class, 'show'])->name('show');
            Route::get('/{staff}/edit', [StaffController::class, 'edit'])
                ->middleware('checkrole:admin,kaur_tu,staf,operator')
                ->name('edit');
            Route::put('/{staff}', [StaffController::class, 'update'])
                ->middleware('checkrole:admin,kaur_tu,staf,operator')
                ->name('update');
            Route::post('/{staff}/documents', [StaffController::class, 'uploadDocument'])
                ->middleware('checkrole:admin,kaur_tu,staf,operator')
                ->name('documents.upload');
            Route::delete('/{staff}/documents/{document}', [StaffController::class, 'destroyDocument'])
                ->middleware('checkrole:admin,kaur_tu,staf,operator')
                ->name('documents.destroy');
        });

        // Struktur Organisasi, Unit Kerja, Jabatan & Penugasan (WP3.4)
        Route::prefix('organisasi')->name('organization.')->group(function () {
            Route::get('/', [OrganizationController::class, 'index'])->name('index');

            Route::middleware('checkrole:admin,kaur_tu,staf,operator')->group(function () {
                Route::post('/unit', [OrganizationController::class, 'storeUnit'])->name('units.store');
                Route::put('/unit/{unit}', [OrganizationController::class, 'updateUnit'])->name('units.update');
                Route::delete('/unit/{unit}', [OrganizationController::class, 'destroyUnit'])->name('units.destroy');

                Route::post('/jabatan', [OrganizationController::class, 'storePosition'])->name('positions.store');
                Route::put('/jabatan/{position}', [OrganizationController::class, 'updatePosition'])->name('positions.update');
                Route::delete('/jabatan/{position}', [OrganizationController::class, 'destroyPosition'])->name('positions.destroy');

                Route::post('/penugasan', [OrganizationController::class, 'storeAssignment'])->name('assignments.store');
                Route::put('/penugasan/{assignment}', [OrganizationController::class, 'updateAssignment'])->name('assignments.update');
                Route::delete('/penugasan/{assignment}', [OrganizationController::class, 'destroyAssignment'])->name('assignments.destroy');
            });
        });

        // Statistik
        Route::get('/statistik/demografi', [StatistikController::class, 'demografi'])->name('statistik.demografi');

        // Persuratan
        Route::prefix('persuratan')->name('persuratan.')->group(function () {
            Route::get('/', [LetterController::class, 'index'])->name('index');
            Route::get('/create', [LetterController::class, 'create'])->name('create');
            Route::post('/', [LetterController::class, 'store'])->name('store');
            Route::get('/{letter}', [LetterController::class, 'show'])->name('show');
            Route::get('/{letter}/edit', [LetterController::class, 'edit'])->name('edit');
            Route::put('/{letter}', [LetterController::class, 'update'])->name('update');
            Route::delete('/{letter}', [LetterController::class, 'destroy'])->name('destroy');
        });

        // Integrasi EMIS
        Route::get('/emis/siswa', fn () => Inertia::render('Emis/Siswa', [
            'integrationTokens' => IntegrationToken::where('purpose', IntegrationToken::PURPOSE_EMIS_SISWA)
                ->orderByDesc('created_at')
                ->get()
                ->append('masked_token'),
        ]))->name('emis.siswa');
        Route::get('/emis/gtk', [EmisGtkController::class, 'index'])->name('emis.gtk');
        Route::post('/emis/gtk/sync', [EmisGtkController::class, 'sync'])->name('emis.gtk.sync');
        Route::get('/emis/gtk/sync-status', [EmisGtkController::class, 'syncStatus'])->name('emis.gtk.sync-status');
        Route::post('/emis/tokens', [IntegrationTokenController::class, 'store'])->name('emis.tokens.store');
        Route::post('/emis/tokens/{integrationToken}/revoke', [IntegrationTokenController::class, 'revoke'])->name('emis.tokens.revoke');

        // Leger Nilai
        Route::get('/kelas/leger', [LegerController::class, 'index'])->name('leger.index');
        Route::get('/kelas/leger/export', [LegerController::class, 'export'])->name('leger.export');
        Route::post('/kelas/leger/grade', [LegerController::class, 'upsertGrade'])->name('leger.grade.upsert');
        Route::post('/kelas/leger/formula', [LegerController::class, 'updateFormula'])->name('leger.formula.update');

        // Master Data Akademik
        Route::prefix('master')->name('master.')->group(function () {
            Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
            Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
            Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
            Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

            Route::get('/classes', [SchoolClassController::class, 'index'])->name('classes.index');
            Route::post('/classes', [SchoolClassController::class, 'store'])->name('classes.store');
            Route::put('/classes/{schoolClass}', [SchoolClassController::class, 'update'])->name('classes.update');
            Route::delete('/classes/{schoolClass}', [SchoolClassController::class, 'destroy'])->name('classes.destroy');

            Route::get('/academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
            Route::post('/academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
            Route::put('/academic-years/{academicYear}', [AcademicYearController::class, 'update'])->name('academic-years.update');
            Route::post('/academic-years/{academicYear}/set-active', [AcademicYearController::class, 'setActive'])->name('academic-years.set-active');
            Route::post('/academic-years/{academicYear}/toggle-lock', [AcademicYearController::class, 'toggleLock'])->name('academic-years.toggle-lock');
            Route::delete('/academic-years/{academicYear}', [AcademicYearController::class, 'destroy'])->name('academic-years.destroy');
        });

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
