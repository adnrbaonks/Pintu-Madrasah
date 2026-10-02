<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\StudentAcademicCohort;
use App\Models\StudentGrade;
use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegerController extends Controller
{
    /**
     * Halaman utama leger — pilih rombel & semester.
     */
    public function index(Request $request): Response
    {
        $activeYear = AcademicYear::active();

        $rombels = Rombel::with('schoolClass')
            ->orderBy('name')
            ->get(['id', 'name', 'class_id'])
            ->map(fn ($r) => ['value' => $r->id, 'label' => $r->name]);

        // Filter terpilih
        $selectedRombelId = $request->rombel_id;
        $selectedSemester = $request->semester ?? 'Ganjil';

        $leger = null;

        if ($selectedRombelId && $activeYear) {
            $rombel = Rombel::findOrFail($selectedRombelId);

            // Ambil mapel sesuai tingkat kelas
            $gradeLevel = $rombel->schoolClass?->grade_level;
            $subjects   = Subject::where('is_active', true)
                ->when($gradeLevel, fn ($q) => $q->where('grade_level', $gradeLevel))
                ->orderBy('order')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'kkm', 'type']);

            // Ambil siswa di rombel ini
            $cohorts = StudentAcademicCohort::with('student:id,name,nisn')
                ->where('rombel_id', $selectedRombelId)
                ->where('academic_year_id', $activeYear->id)
                ->where('status', 'active')
                ->orderBy('attendance_number')
                ->get();

            // Ambil semua nilai yang ada untuk kombinasi ini
            $grades = StudentGrade::where('rombel_id', $selectedRombelId)
                ->where('academic_year_id', $activeYear->id)
                ->where('semester', $selectedSemester)
                ->get()
                ->keyBy(fn ($g) => "{$g->student_id}_{$g->subject_id}");

            // Susun data leger
            $siswaData = $cohorts->map(function ($cohort) use ($subjects, $grades) {
                $student = $cohort->student;
                $nilaiRow = [];

                foreach ($subjects as $subj) {
                    $key = "{$student->id}_{$subj->id}";
                    $grade = $grades->get($key);

                    $nilaiRow[$subj->id] = [
                        'id'          => $grade?->id,
                        'nilai_p1'    => $grade?->nilai_p1,
                        'nilai_p2'    => $grade?->nilai_p2,
                        'nilai_sts'   => $grade?->nilai_sts,
                        'nilai_sas'   => $grade?->nilai_sas,
                        'nilai_akhir' => $grade?->nilai_akhir,
                        'predikat'    => $grade?->predikat,
                    ];
                }

                return [
                    'student_id'        => $student->id,
                    'name'              => $student->name,
                    'nisn'              => $student->nisn,
                    'attendance_number' => $cohort->attendance_number,
                    'nilai'             => $nilaiRow,
                ];
            });

            $leger = [
                'rombel'   => $rombel->name,
                'subjects' => $subjects,
                'siswa'    => $siswaData,
                'semester' => $selectedSemester,
            ];
        }

        return Inertia::render('Leger/Index', [
            'rombels'          => $rombels,
            'selectedRombelId' => $selectedRombelId,
            'selectedSemester' => $selectedSemester,
            'tahunAjaran'      => $activeYear?->full_label ?? '—',
            'leger'            => $leger,
        ]);
    }

    /**
     * Simpan/update nilai satu siswa satu mapel.
     */
    public function upsertGrade(Request $request)
    {
        $data = $request->validate([
            'student_id'      => 'required|exists:students,id',
            'subject_id'      => 'required|exists:subjects,id',
            'rombel_id'       => 'required|exists:rombels,id',
            'academic_year_id'=> 'required|exists:academic_years,id',
            'semester'        => 'required|in:Ganjil,Genap',
            'nilai_p1'        => 'nullable|numeric|between:0,100',
            'nilai_p2'        => 'nullable|numeric|between:0,100',
            'nilai_sts'       => 'nullable|numeric|between:0,100',
            'nilai_sas'       => 'nullable|numeric|between:0,100',
        ]);

        $grade = StudentGrade::updateOrCreate(
            [
                'student_id'       => $data['student_id'],
                'subject_id'       => $data['subject_id'],
                'academic_year_id' => $data['academic_year_id'],
                'semester'         => $data['semester'],
            ],
            [
                'rombel_id' => $data['rombel_id'],
                'nilai_p1'  => $data['nilai_p1'],
                'nilai_p2'  => $data['nilai_p2'],
                'nilai_sts' => $data['nilai_sts'],
                'nilai_sas' => $data['nilai_sas'],
            ]
        );

        return response()->json([
            'id'          => $grade->id,
            'nilai_akhir' => $grade->fresh()->nilai_akhir,
            'predikat'    => $grade->hitungPredikat(),
        ]);
    }
}
