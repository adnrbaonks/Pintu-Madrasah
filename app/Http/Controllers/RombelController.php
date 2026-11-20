<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\StudentAcademicCohort;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RombelController extends Controller
{
    public function index(Request $request)
    {
        $academicYears = AcademicYear::orderBy('year', 'desc')->get(['id', 'year', 'semester', 'is_active']);
        $activeYear = AcademicYear::active();

        $yearId = $request->get('academic_year_id', $activeYear?->id);

        $baseQuery = Rombel::with(['schoolClass:id,grade_level,major_name', 'homeroomTeacher:id,full_name_with_titles'])
            ->withCount('cohorts as student_count')
            ->join('classes', 'rombels.class_id', '=', 'classes.id')
            ->orderBy('classes.grade_level')
            ->orderBy('rombels.name')
            ->select('rombels.*');

        if ($yearId) {
            $baseQuery->where('rombels.academic_year_id', $yearId);
        }

        if ($request->filled('grade')) {
            $baseQuery->whereHas('schoolClass', fn ($q) => $q->where('grade_level', $request->grade));
        }

        // Summary dari SEMUA rombel (tanpa filter tingkat) untuk kartu ringkasan
        $allForSummary = Rombel::with('schoolClass:id,grade_level')
            ->withCount('cohorts as student_count')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->get();

        $summary = $allForSummary->groupBy(fn ($r) => $r->schoolClass?->grade_level)
            ->map(fn ($group, $grade) => [
                'grade' => $grade,
                'rombel_count' => $group->count(),
                'student_total' => $group->sum('student_count'),
            ])->sortKeys()->values();

        // Paginate tabel rombel (10 per halaman)
        $rombels = $baseQuery->paginate(10)->withQueryString();

        // Map data untuk frontend
        $rombels->getCollection()->transform(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'grade_level' => $r->schoolClass?->grade_level,
            'homeroom' => $r->homeroomTeacher?->full_name_with_titles,
            'student_count' => $r->student_count,
        ]);

        return Inertia::render('Rombel/Index', [
            'rombels' => $rombels,
            'summary' => $summary,
            'academicYears' => $academicYears,
            'filters' => $request->only(['academic_year_id', 'grade']),
            'selectedYear' => $yearId,
        ]);
    }

    public function show(Rombel $rombel, Request $request)
    {
        $rombel->load(['schoolClass:id,grade_level,major_name', 'homeroomTeacher:id,full_name_with_titles', 'academicYear:id,year,semester']);

        $studentsPaginated = StudentAcademicCohort::with('student:id,name,nisn,gender,birth_date,status')
            ->where('rombel_id', $rombel->id)
            ->paginate(20)->withQueryString();

        $studentsPaginated->getCollection()->transform(fn ($c) => [
            'id' => $c->student->id,
            'name' => $c->student->name,
            'nisn' => $c->student->nisn,
            'gender' => $c->student->gender,
            'birth_date' => $c->student->birth_date?->format('d/m/Y'),
            'status' => $c->student->status,
        ]);

        // Hitung total L/P dari semua cohort (bukan hanya halaman ini)
        $genderStats = StudentAcademicCohort::with('student:id,gender')
            ->where('rombel_id', $rombel->id)
            ->get()
            ->groupBy(fn ($c) => $c->student?->gender);

        return Inertia::render('Rombel/Show', [
            'rombel' => [
                'id' => $rombel->id,
                'name' => $rombel->name,
                'grade_level' => $rombel->schoolClass?->grade_level,
                'homeroom' => $rombel->homeroomTeacher?->full_name_with_titles,
                'year' => $rombel->academicYear?->year.' '.$rombel->academicYear?->semester,
            ],
            'students' => $studentsPaginated,
            'gender_stats' => [
                'L' => $genderStats->get('L', collect())->count(),
                'P' => $genderStats->get('P', collect())->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'academic_year_id' => 'required|uuid',
            'class_id' => 'required|uuid',
            'homeroom_teacher_id' => 'nullable|uuid',
        ]);

        Rombel::create($request->only(['name', 'academic_year_id', 'class_id', 'homeroom_teacher_id']));

        return back()->with('success', "Rombel '{$request->name}' berhasil dibuat.");
    }

    public function update(Request $request, Rombel $rombel)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'homeroom_teacher_id' => 'nullable|uuid',
        ]);

        $rombel->update($request->only(['name', 'homeroom_teacher_id']));

        return back()->with('success', "Rombel '{$rombel->name}' berhasil diperbarui.");
    }

    public function destroy(Rombel $rombel)
    {
        if ($rombel->cohorts()->exists()) {
            return back()->withErrors(['delete' => "Rombel '{$rombel->name}' tidak dapat dihapus karena masih memiliki siswa."]);
        }

        $name = $rombel->name;
        $rombel->delete();

        return back()->with('success', "Rombel '{$name}' berhasil dihapus.");
    }
}
