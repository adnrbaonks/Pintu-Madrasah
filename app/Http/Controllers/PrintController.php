<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PrintController extends Controller
{
    /**
     * Halaman utama format cetak (stepper).
     */
    public function index()
    {
        $academicYears = AcademicYear::orderByDesc('created_at')->get()->map(fn ($ay) => [
            'id' => $ay->id,
            'label' => $ay->year.' – '.$ay->semester,
        ]);

        $rombels = Rombel::with(['academicYear', 'homeroomTeacher'])
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'academic_year_id' => $r->academic_year_id,
                'homeroom_teacher' => $r->homeroomTeacher?->name ?? '-',
            ]);

        return Inertia::render('Print/Index', [
            'academicYears' => $academicYears,
            'rombels' => $rombels,
        ]);
    }

    /**
     * Data Daftar Siswa per Kelas untuk preview/cetak.
     */
    public function daftarSiswa(Request $request)
    {
        $request->validate(['rombel_id' => 'required|exists:rombels,id']);

        $rombel = Rombel::with(['academicYear', 'homeroomTeacher'])
            ->findOrFail($request->rombel_id);

        $cohorts = $rombel->cohorts()
            ->with('student')
            ->orderBy('attendance_number')
            ->get();

        $students = $cohorts->map(fn ($c) => [
            'no' => $c->attendance_number,
            'name' => $c->student->name,
            'nisn' => $c->student->nisn ?? '-',
            'nism' => $c->student->nis_local ?? '-',
            'gender' => $c->student->gender === 'L' ? 'L' : 'P',
            'birth_place' => $c->student->birth_place ?? '-',
            'birth_date' => $c->student->birth_date
                                    ? Carbon::parse($c->student->birth_date)->format('d-m-Y')
                                    : '-',
            'address' => $c->student->address ?? '-',
            'father_name' => $c->student->father_name ?? '-',
            'mother_name' => $c->student->mother_name ?? '-',
        ]);

        $settings = Setting::all()->pluck('value', 'key');

        return Inertia::render('Print/DaftarSiswa', [
            'rombel' => [
                'name' => $rombel->name,
                'academic_year' => $rombel->academicYear->year,
                'semester' => $rombel->academicYear->semester,
                'homeroom_teacher' => $rombel->homeroomTeacher?->name ?? '-',
            ],
            'students' => $students,
            'settings' => $settings,
        ]);
    }

    /**
     * Data Daftar Kelas untuk preview/cetak (format KELAS 7/8/9 dari Excel).
     * Kolom: NO | NIS | NISN | NAMA | JK | 1 | 2 | 3 | 4 | KET
     */
    public function daftarKelas(Request $request)
    {
        $request->validate(['rombel_id' => 'required|exists:rombels,id']);

        $rombel = Rombel::with(['academicYear', 'homeroomTeacher'])
            ->findOrFail($request->rombel_id);

        $cohorts = $rombel->cohorts()
            ->with('student')
            ->orderBy('attendance_number')
            ->get();

        $students = $cohorts->map(fn ($c) => [
            'no' => $c->attendance_number,
            'nis' => $c->student->nis_local ?? '-',
            'nisn' => $c->student->nisn ?? '-',
            'name' => $c->student->name,
            'gender' => $c->student->gender === 'L' ? 'L' : 'P',
        ]);

        $settings = Setting::all()->pluck('value', 'key');

        return Inertia::render('Print/DaftarKelas', [
            'rombel' => [
                'name' => $rombel->name,
                'academic_year' => $rombel->academicYear->year,
                'semester' => $rombel->academicYear->semester,
                'homeroom_teacher' => $rombel->homeroomTeacher?->name ?? '-',
            ],
            'students' => $students,
            'settings' => $settings,
        ]);
    }

    /**
     * Data Absen Harian untuk preview/cetak.
     */
    public function absenHarian(Request $request)
    {
        $request->validate([
            'rombel_id' => 'required|exists:rombels,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2099',
        ]);

        $rombel = Rombel::with(['academicYear', 'homeroomTeacher'])
            ->findOrFail($request->rombel_id);

        $cohorts = $rombel->cohorts()
            ->with('student')
            ->orderBy('attendance_number')
            ->get();

        $students = $cohorts->map(fn ($c) => [
            'no' => $c->attendance_number,
            'name' => $c->student->name,
            'gender' => $c->student->gender === 'L' ? 'L' : 'P',
        ]);

        // Hitung jumlah hari dalam bulan
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $request->month, $request->year);

        $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        $settings = Setting::all()->pluck('value', 'key');

        return Inertia::render('Print/AbsenHarian', [
            'rombel' => [
                'name' => $rombel->name,
                'academic_year' => $rombel->academicYear->year,
                'semester' => $rombel->academicYear->semester,
                'homeroom_teacher' => $rombel->homeroomTeacher?->name ?? '-',
            ],
            'students' => $students,
            'month' => $request->month,
            'monthName' => $months[$request->month],
            'year' => $request->year,
            'daysInMonth' => $daysInMonth,
            'settings' => $settings,
        ]);
    }
}
