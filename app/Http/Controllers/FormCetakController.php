<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\Staff;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FormCetakController extends Controller
{
    public function index()
    {
        $activeYear = AcademicYear::active();

        $rombels = [];
        if ($activeYear) {
            $rombels = Rombel::where('academic_year_id', $activeYear->id)
                ->with(['schoolClass', 'homeroomTeacher'])
                ->orderBy('name')
                ->get()
                ->map(fn($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'grade_level' => $r->schoolClass?->grade_level ?? '-',
                    'wali_kelas' => $r->homeroomTeacher?->full_name_with_titles ?? '-',
                ]);
        }

        return Inertia::render('Students/FormCetak', [
            'activeYear' => $activeYear,
            'rombels' => $rombels,
            'madrasahName' => env('MADRASAH_NAME', 'MTs Negeri 5 Sumedang'),
            'madrasahAddress' => env('MADRASAH_ADDRESS', ''),
        ]);
    }

    public function getData(Request $request)
    {
        $request->validate([
            'rombel_id' => 'required|exists:rombels,id',
        ]);

        $rombel = Rombel::with(['schoolClass', 'homeroomTeacher', 'academicYear'])
            ->findOrFail($request->rombel_id);

        $students = \App\Models\StudentAcademicCohort::where('rombel_id', $rombel->id)
            ->where('academic_year_id', $rombel->academic_year_id)
            ->with('student')
            ->orderBy('attendance_number')
            ->get()
            ->map(fn($c) => [
                'attendance_number' => $c->attendance_number,
                'name' => $c->student?->name ?? '-',
                'gender' => $c->student?->gender ?? '-',
                'nisn' => $c->student?->nisn ?? '-',
                'nis_local' => $c->student?->nis_local ?? '-',
            ]);

        return response()->json([
            'rombel' => [
                'name' => $rombel->name,
                'grade_level' => $rombel->schoolClass?->grade_level ?? '-',
                'wali_kelas' => $rombel->homeroomTeacher?->full_name_with_titles ?? '-',
                'year' => $rombel->academicYear?->year ?? '-',
                'semester' => $rombel->academicYear?->semester ?? '-',
            ],
            'students' => $students,
        ]);
    }
}
