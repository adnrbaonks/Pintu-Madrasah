<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\GradingFormula;
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
            $subjects = Subject::where('is_active', true)
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
                        'id' => $grade?->id,
                        'nilai_p1' => $grade?->nilai_p1,
                        'nilai_p2' => $grade?->nilai_p2,
                        'nilai_sts' => $grade?->nilai_sts,
                        'nilai_sas' => $grade?->nilai_sas,
                        'nilai_akhir' => $grade?->nilai_akhir,
                        'predikat' => $grade?->predikat,
                    ];
                }

                return [
                    'student_id' => $student->id,
                    'name' => $student->name,
                    'nisn' => $student->nisn,
                    'attendance_number' => $cohort->attendance_number,
                    'nilai' => $nilaiRow,
                ];
            });

            $leger = [
                'rombel' => $rombel->name,
                'subjects' => $subjects,
                'siswa' => $siswaData,
                'semester' => $selectedSemester,
            ];
        }

        $formula = $activeYear ? GradingFormula::where('academic_year_id', $activeYear->id)->first() : null;
        if (! $formula && $activeYear) {
            $formula = GradingFormula::defaultFormula($activeYear->id);
        }

        return Inertia::render('Leger/Index', [
            'rombels' => $rombels,
            'selectedRombelId' => $selectedRombelId,
            'selectedSemester' => $selectedSemester,
            'tahunAjaran' => $activeYear?->full_label ?? '—',
            'academicYearId' => $activeYear?->id,
            'formula' => $formula,
            'leger' => $leger,
        ]);
    }

    /**
     * Ekspor CSV siap salin-tempel ke template RDM.
     * - Tanpa subject_id: format lebar (1 baris/siswa, kolom per mapel = nilai akhir).
     * - Dengan subject_id: format per mapel (No, NISN, NIS, Nama, P1, P2, STS, SAS, Akhir, Predikat).
     * Template RDM per-instalasi terproteksi, jadi CSV ini menjadi jembatan salin-tempel.
     */
    public function export(Request $request)
    {
        $request->validate([
            'rombel_id' => 'required|exists:rombels,id',
            'semester' => 'required|in:Ganjil,Genap',
            'subject_id' => 'nullable|exists:subjects,id',
        ]);

        $activeYear = AcademicYear::active();
        abort_unless($activeYear, 422, 'Tahun ajaran aktif belum diatur.');

        $rombel = Rombel::findOrFail($request->rombel_id);

        $cohorts = StudentAcademicCohort::with('student:id,name,nisn,nis_local')
            ->where('rombel_id', $rombel->id)
            ->where('academic_year_id', $activeYear->id)
            ->where('status', 'active')
            ->orderBy('attendance_number')
            ->get();

        $fileName = 'RDM_'.preg_replace('/\W+/', '_', $rombel->name).'_'.$request->semester.'.csv';

        $callback = function () use ($cohorts, $request, $activeYear, $rombel) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF"); // BOM agar Excel baca UTF-8

            if ($request->subject_id) {
                $subject = Subject::findOrFail($request->subject_id);
                fputcsv($file, ['No', 'NISN', 'NIS', 'Nama', 'P1', 'P2', 'STS', 'SAS', 'Nilai Akhir', 'Predikat']);
                $grades = StudentGrade::where('rombel_id', $rombel->id)
                    ->where('academic_year_id', $activeYear->id)
                    ->where('semester', $request->semester)
                    ->where('subject_id', $subject->id)
                    ->get()->keyBy('student_id');
                $no = 1;
                foreach ($cohorts as $c) {
                    $g = $grades->get($c->student_id);
                    fputcsv($file, [
                        $no++, $c->student?->nisn, $c->student?->nis_local, $c->student?->name,
                        $g?->nilai_p1, $g?->nilai_p2, $g?->nilai_sts, $g?->nilai_sas,
                        $g?->nilai_akhir, $g?->predikat,
                    ]);
                }
            } else {
                $gradeLevel = $rombel->schoolClass?->grade_level;
                $subjects = Subject::where('is_active', true)
                    ->when($gradeLevel, fn ($q) => $q->where('grade_level', $gradeLevel))
                    ->orderBy('order')->orderBy('name')->get();
                $header = ['No', 'NISN', 'NIS', 'Nama'];
                foreach ($subjects as $s) {
                    $header[] = $s->code.' (P1|P2|STS|SAS|Akhir)';
                }
                fputcsv($file, $header);
                $grades = StudentGrade::where('rombel_id', $rombel->id)
                    ->where('academic_year_id', $activeYear->id)
                    ->where('semester', $request->semester)
                    ->get()->keyBy(fn ($g) => "{$g->student_id}_{$g->subject_id}");
                $no = 1;
                foreach ($cohorts as $c) {
                    $row = [$no++, $c->student?->nisn, $c->student?->nis_local, $c->student?->name];
                    foreach ($subjects as $s) {
                        $g = $grades->get("{$c->student_id}_{$s->id}");
                        $row[] = implode('|', [$g?->nilai_p1, $g?->nilai_p2, $g?->nilai_sts, $g?->nilai_sas, $g?->nilai_akhir]);
                    }
                    fputcsv($file, $row);
                }
            }
            fclose($file);
        };

        return response()->streamDownload($callback, $fileName, ['Content-Type' => 'text/csv']);
    }

    /**
     * Simpan/update nilai satu siswa satu mapel.
     */
    public function upsertGrade(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'rombel_id' => 'required|exists:rombels,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'semester' => 'required|in:Ganjil,Genap',
            'nilai_p1' => 'nullable|numeric|between:0,100',
            'nilai_p2' => 'nullable|numeric|between:0,100',
            'nilai_sts' => 'nullable|numeric|between:0,100',
            'nilai_sas' => 'nullable|numeric|between:0,100',
        ]);

        $grade = StudentGrade::updateOrCreate(
            [
                'student_id' => $data['student_id'],
                'subject_id' => $data['subject_id'],
                'academic_year_id' => $data['academic_year_id'],
                'semester' => $data['semester'],
            ],
            [
                'rombel_id' => $data['rombel_id'],
                'nilai_p1' => $data['nilai_p1'],
                'nilai_p2' => $data['nilai_p2'],
                'nilai_sts' => $data['nilai_sts'],
                'nilai_sas' => $data['nilai_sas'],
            ]
        );

        return response()->json([
            'id' => $grade->id,
            'nilai_akhir' => $grade->fresh()->nilai_akhir,
            'predikat' => $grade->fresh()->predikat,
        ]);
    }

    /**
     * Simpan/update formula penilaian per tahun ajaran.
     */
    public function updateFormula(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'weight_p1' => 'required|numeric|min:0|max:100',
            'weight_p2' => 'required|numeric|min:0|max:100',
            'weight_sts' => 'required|numeric|min:0|max:100',
            'weight_sas' => 'required|numeric|min:0|max:100',
            'threshold_a' => 'required|numeric|min:0|max:100',
            'threshold_b' => 'required|numeric|min:0|max:100',
            'threshold_c' => 'required|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $totalWeight = $data['weight_p1'] + $data['weight_p2'] + $data['weight_sts'] + $data['weight_sas'];
        if (abs($totalWeight - 100.0) > 0.01) {
            return back()->with('error', 'Total bobot nilai harus sama dengan 100%. (Saat ini: '.$totalWeight.'%)');
        }

        if ($data['threshold_a'] <= $data['threshold_b'] || $data['threshold_b'] <= $data['threshold_c']) {
            return back()->with('error', 'Ambang batas predikat harus berurutan (A > B > C).');
        }

        GradingFormula::updateOrCreate(
            ['academic_year_id' => $data['academic_year_id']],
            $data
        );

        return back()->with('success', 'Formula penilaian berhasil disimpan.');
    }
}
