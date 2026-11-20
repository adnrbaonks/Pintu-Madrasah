<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\Rombel;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $activeYear = AcademicYear::active();

        // ── Statistik Siswa ────────────────────────────────────────────
        $siswaByGender = Student::where('status', 'active')
            ->select('gender', DB::raw('count(*) as total'))
            ->groupBy('gender')
            ->pluck('total', 'gender');

        $totalSiswa = $siswaByGender->sum();
        $siswaL = $siswaByGender->get('L', 0);
        $siswaP = $siswaByGender->get('P', 0);

        // Siswa per rombel (untuk chart bar)
        $siswaPerRombel = Rombel::with(['cohorts' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'total' => $r->cohorts->count(),
            ]);

        // Kelengkapan data siswa
        $totalSiswaAktif = Student::where('status', 'active')->count();
        $siswaWithNik = Student::where('status', 'active')->whereNotNull('nik')->where('nik', '!=', '')->count();
        $siswaVerifiedAi = Student::where('status', 'active')->where('is_verified_by_ai', true)->count();
        $siswaBerNisn = Student::where('status', 'active')->whereNotNull('nisn')->where('nisn', '!=', '')->count();

        // ── Statistik GTK ──────────────────────────────────────────────
        $gtkByStatus = Staff::where('is_active', true)
            ->select('employment_status', DB::raw('count(*) as total'))
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');

        $totalGtk = $gtkByStatus->sum();
        $gtkPns = $gtkByStatus->get('pns', 0);
        $gtkPppk = $gtkByStatus->get('p3k', $gtkByStatus->get('pppk', 0));
        $gtkHonorer = $gtkByStatus->get('honorer', 0) + $gtkByStatus->get('gtt', 0) + $gtkByStatus->get('ptt', 0);
        $gtkSertifikasi = Staff::where('is_active', true)->where('tpg_status', true)->count();

        $gtkByGender = Staff::where('is_active', true)
            ->select('gender', DB::raw('count(*) as total'))
            ->groupBy('gender')
            ->pluck('total', 'gender');

        // ── PPDB ───────────────────────────────────────────────────────
        $ppdbPending = PpdbRegistration::where('status', 'pending')->count();
        $ppdbAccepted = PpdbRegistration::where('status', 'accepted')->count();
        $ppdbTotal = PpdbRegistration::count();

        // ── Rombel ─────────────────────────────────────────────────────
        $totalRombel = Rombel::count();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalSiswa' => $totalSiswa,
                'siswaL' => $siswaL,
                'siswaP' => $siswaP,
                'totalGtk' => $totalGtk,
                'gtkPns' => $gtkPns,
                'gtkPppk' => $gtkPppk,
                'gtkHonorer' => $gtkHonorer,
                'gtkSertifikasi' => $gtkSertifikasi,
                'totalRombel' => $totalRombel,
                'tahunAjaran' => $activeYear?->full_label ?? 'Belum ada tahun ajaran aktif',
                'ppdbPending' => $ppdbPending,
                'ppdbAccepted' => $ppdbAccepted,
                'ppdbTotal' => $ppdbTotal,
            ],
            'completeness' => [
                'siswaWithNik' => $totalSiswaAktif > 0 ? round(($siswaWithNik / $totalSiswaAktif) * 100) : 0,
                'siswaVerifiedAi' => $totalSiswaAktif > 0 ? round(($siswaVerifiedAi / $totalSiswaAktif) * 100) : 0,
                'siswaBerNisn' => $totalSiswaAktif > 0 ? round(($siswaBerNisn / $totalSiswaAktif) * 100) : 0,
            ],
            'charts' => [
                'genderSiswa' => [
                    ['name' => 'Laki-laki', 'value' => (int) $siswaL,  'color' => '#0ea5e9'],
                    ['name' => 'Perempuan',  'value' => (int) $siswaP,  'color' => '#f43f5e'],
                ],
                'komposisiGtk' => [
                    ['name' => 'PNS',     'value' => (int) $gtkPns,     'color' => '#10b981'],
                    ['name' => 'PPPK',    'value' => (int) $gtkPppk,    'color' => '#f59e0b'],
                    ['name' => 'Honorer', 'value' => (int) $gtkHonorer, 'color' => '#8b5cf6'],
                ],
                'siswaPerRombel' => $siswaPerRombel,
            ],
        ]);
    }
}
