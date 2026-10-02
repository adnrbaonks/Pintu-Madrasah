<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentAcademicCohort;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StatistikController extends Controller
{
    public function demografi(): Response
    {
        $activeYear = AcademicYear::active();

        // ── Usia Siswa ─────────────────────────────────────────────────
        $usiaDist = Student::where('status', 'active')
            ->whereNotNull('birth_date')
            ->selectRaw("(strftime('%Y', 'now') - strftime('%Y', birth_date)) as usia, count(*) as total")
            ->groupBy('usia')
            ->orderBy('usia')
            ->get()
            ->map(fn ($r) => ['usia' => (int) $r->usia, 'total' => (int) $r->total]);

        // ── Bantuan Sosial ─────────────────────────────────────────────
        $bansos = [
            ['label' => 'KIP (Kartu Indonesia Pintar)', 'key' => 'kip',
             'total' => Student::where('status', 'active')->whereNotNull('kip_number')->where('kip_number', '!=', '')->count()],
            ['label' => 'PIP Eligible', 'key' => 'pip',
             'total' => Student::where('status', 'active')->where('pip_eligible', true)->count()],
            ['label' => 'PKH (Program Keluarga Harapan)', 'key' => 'pkh',
             'total' => Student::where('status', 'active')->where('pkh_eligible', true)->count()],
            ['label' => 'KKS (Kartu Keluarga Sejahtera)', 'key' => 'kks',
             'total' => Student::where('status', 'active')->where('kks_eligible', true)->count()],
        ];

        // ── Living Status ──────────────────────────────────────────────
        $livingStatus = Student::where('status', 'active')
            ->select('living_status', DB::raw('count(*) as total'))
            ->groupBy('living_status')
            ->get()
            ->map(fn ($r) => ['name' => $r->living_status ?? 'Tidak diisi', 'value' => (int) $r->total]);

        // ── Transport ke Sekolah ───────────────────────────────────────
        $transport = Student::where('status', 'active')
            ->select('transportation', DB::raw('count(*) as total'))
            ->groupBy('transportation')
            ->get()
            ->map(fn ($r) => ['name' => $r->transportation ?? 'Tidak diisi', 'value' => (int) $r->total]);

        // ── Siswa per Rombel dengan gender breakdown ───────────────────
        $rombelBreakdown = Rombel::leftJoin('student_academic_cohorts', function ($join) use ($activeYear) {
                $join->on('rombels.id', '=', 'student_academic_cohorts.rombel_id')
                     ->where('student_academic_cohorts.status', 'active');
                if ($activeYear) {
                    $join->where('student_academic_cohorts.academic_year_id', $activeYear->id);
                }
            })
            ->leftJoin('students', 'student_academic_cohorts.student_id', '=', 'students.id')
            ->select(
                'rombels.name as rombel',
                DB::raw("SUM(CASE WHEN students.gender = 'L' THEN 1 ELSE 0 END) as laki"),
                DB::raw("SUM(CASE WHEN students.gender = 'P' THEN 1 ELSE 0 END) as perempuan"),
                DB::raw('COUNT(students.id) as total'),
            )
            ->groupBy('rombels.name', 'rombels.id')
            ->orderBy('rombels.name')
            ->get();

        // ── GTK Demografi ──────────────────────────────────────────────
        $gtkAgeGroups = Staff::where('is_active', true)
            ->whereNotNull('birth_date')
            ->selectRaw("
                CASE
                    WHEN (strftime('%Y', 'now') - strftime('%Y', birth_date)) < 30 THEN '< 30 tahun'
                    WHEN (strftime('%Y', 'now') - strftime('%Y', birth_date)) < 40 THEN '30–39 tahun'
                    WHEN (strftime('%Y', 'now') - strftime('%Y', birth_date)) < 50 THEN '40–49 tahun'
                    WHEN (strftime('%Y', 'now') - strftime('%Y', birth_date)) < 60 THEN '50–59 tahun'
                    ELSE '60+ tahun'
                END as kelompok_usia,
                count(*) as total
            ")
            ->groupBy('kelompok_usia')
            ->orderBy(DB::raw('MIN(birth_date)'), 'desc')
            ->get()
            ->map(fn ($r) => ['name' => $r->kelompok_usia, 'value' => (int) $r->total]);

        $gtkEducation = Staff::where('is_active', true)
            ->select('highest_education', DB::raw('count(*) as total'))
            ->groupBy('highest_education')
            ->get()
            ->map(fn ($r) => ['name' => $r->highest_education ?? 'Tidak diisi', 'value' => (int) $r->total]);

        return Inertia::render('Statistik/Demografi', [
            'tahunAjaran'    => $activeYear?->full_label ?? '—',
            'usiaDist'       => $usiaDist,
            'bansos'         => $bansos,
            'livingStatus'   => $livingStatus,
            'transport'      => $transport,
            'rombelBreakdown'=> $rombelBreakdown,
            'gtkAgeGroups'   => $gtkAgeGroups,
            'gtkEducation'   => $gtkEducation,
        ]);
    }

    public function tpg(): Response
    {
        $staff = Staff::where('is_active', true)
            ->select('id', 'full_name_with_titles', 'nuptk', 'nip', 'npk', 'employment_status',
                     'functional_position', 'highest_education', 'tpg_status', 'civil_rank', 'gender')
            ->orderBy('full_name_with_titles')
            ->get();

        $summary = [
            'total'       => $staff->count(),
            'sudah_tpg'   => $staff->where('tpg_status', true)->count(),
            'belum_tpg'   => $staff->where('tpg_status', false)->count(),
            'pns'         => $staff->where('employment_status', 'pns')->count(),
            'pppk'        => $staff->whereIn('employment_status', ['p3k', 'pppk'])->count(),
            'honorer'     => $staff->whereIn('employment_status', ['honorer', 'gtt', 'ptt'])->count(),
        ];

        return Inertia::render('Gtk/Tpg', [
            'staff'   => $staff,
            'summary' => $summary,
        ]);
    }

    public function updateTpgStatus(\Illuminate\Http\Request $request, Staff $staff): \Illuminate\Http\JsonResponse
    {
        $request->validate(['tpg_status' => 'required|boolean']);
        $staff->update(['tpg_status' => $request->tpg_status]);
        return response()->json(['success' => true]);
    }
}
