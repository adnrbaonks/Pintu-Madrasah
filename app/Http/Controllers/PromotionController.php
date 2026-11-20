<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\Student;
use App\Models\StudentAcademicCohort;
use App\Support\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PromotionController extends Controller
{
    public function index()
    {
        $academicYears = AcademicYear::orderBy('year', 'desc')->get(['id', 'year', 'semester', 'is_active']);
        $rombels = Rombel::with('schoolClass:id,grade_level,major_name')
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'academic_year_id' => $r->academic_year_id,
                'grade_level' => $r->schoolClass?->grade_level,
            ]);

        return Inertia::render('Students/Promotion/Index', [
            'academicYears' => $academicYears,
            'rombels' => $rombels,
        ]);
    }

    /**
     * Ambil siswa dari rombel asal yang BELUM terdaftar di tahun ajaran tujuan.
     */
    public function getStudents(Request $request)
    {
        $request->validate([
            'rombel_id' => 'required|uuid',
            'target_academic_year_id' => 'nullable|uuid',
        ]);

        $rombel = Rombel::with('schoolClass:id,grade_level')->findOrFail($request->rombel_id);

        // Ambil ID siswa yang sudah ada di tahun ajaran tujuan (untuk disaring)
        $alreadyPromotedIds = [];
        if ($request->filled('target_academic_year_id')) {
            $alreadyPromotedIds = StudentAcademicCohort::where('academic_year_id', $request->target_academic_year_id)
                ->pluck('student_id')
                ->toArray();
        }

        $students = StudentAcademicCohort::with('student:id,name,nisn,gender')
            ->where('rombel_id', $request->rombel_id)
            ->whereNotIn('student_id', $alreadyPromotedIds)
            ->whereHas('student', fn ($q) => $q->where('status', 'active'))
            ->paginate($request->per_page ?? 5)
            ->through(fn ($cohort) => [
                'id' => $cohort->student->id,
                'name' => $cohort->student->name,
                'nisn' => $cohort->student->nisn,
                'gender' => $cohort->student->gender,
            ]);

        // Deteksi apakah rombel ini adalah kelas akhir (misal VI, IX, atau XII)
        $grade = $rombel->schoolClass->grade_level ?? '';
        $isLastGrade = in_array($grade, ['VI', 'IX', 'XII']);

        return response()->json([
            'students' => $students,
            'is_last_grade' => $isLastGrade,
            'grade_level' => $rombel->schoolClass?->grade_level,
        ]);
    }

    /**
     * Proses kenaikan kelas massal ke rombel baru.
     */
    public function promote(Request $request)
    {
        $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'uuid',
            'target_academic_year_id' => 'required|uuid',
            'target_rombel_id' => 'required|uuid',
        ]);

        $targetAcademicYear = AcademicYear::findOrFail($request->target_academic_year_id);

        DB::transaction(function () use ($request, $targetAcademicYear) {
            foreach ($request->student_ids as $studentId) {
                // Skip jika sudah ada di tahun ajaran tujuan
                $exists = StudentAcademicCohort::where('student_id', $studentId)
                    ->where('academic_year_id', $request->target_academic_year_id)
                    ->exists();

                if (! $exists) {
                    StudentAcademicCohort::create([
                        'student_id' => $studentId,
                        'academic_year_id' => $request->target_academic_year_id,
                        'semester' => $targetAcademicYear->semester,
                        'rombel_id' => $request->target_rombel_id,
                        'status' => 'active',
                    ]);
                }
            }
        });

        $targetRombel = Rombel::find($request->target_rombel_id);

        Webhook::fire('student.promoted', [
            'student_ids' => $request->student_ids,
            'target_academic_year_id' => $request->target_academic_year_id,
            'target_rombel_id' => $request->target_rombel_id,
            'target_rombel' => $targetRombel?->name,
        ]);

        return back()->with('success', count($request->student_ids).' siswa berhasil dinaikkan kelasnya.');
    }

    /**
     * Proses kelulusan massal (hanya untuk kelas akhir).
     */
    public function graduate(Request $request)
    {
        $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'uuid',
        ]);

        DB::transaction(function () use ($request) {
            Student::whereIn('id', $request->student_ids)
                ->update(['status' => 'graduated']);
        });

        // Mass-update bypass model events → fire manual (batch)
        Webhook::fire('student.mutated', [
            'student_ids' => $request->student_ids,
            'reason' => 'graduated',
        ]);

        return back()->with('success', count($request->student_ids).' siswa berhasil diluluskan.');
    }
}
