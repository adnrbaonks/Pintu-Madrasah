<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Rombel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class StudentController extends Controller
{
    /**
     * Display a listing of the students.
     */
    public function index(Request $request)
    {
        $query = Student::query()
            ->with(['currentCohort.rombel', 'currentCohort.academicYear']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nisn', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $students = $query->orderBy('name')->paginate(10)->withQueryString();

        return Inertia::render('Students/Index', [
            'students' => $students,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Display the specified student.
     */
    public function show(Student $student)
    {
        $student->load(['cohorts.rombel', 'cohorts.academicYear', 'documents']);

        return Inertia::render('Students/Show', [
            'student' => $student,
        ]);
    }

    /**
     * Print Student Biodata to PDF.
     */
    public function printBiodata(Student $student)
    {
        $student->load(['currentCohort.rombel', 'currentCohort.academicYear']);

        $pdf = Pdf::loadView('pdf.student-biodata', [
            'student' => $student,
            'madrasahName' => env('MADRASAH_NAME', 'MTs Negeri Contoh'),
            'madrasahAddress' => env('MADRASAH_ADDRESS', 'Jl. Contoh No. 1'),
        ]);

        return $pdf->stream("Biodata_{$student->nisn}_{$student->name}.pdf");
    }

    /**
     * Print Exam Cards for a specific Rombel.
     */
    public function printExamCards(Request $request)
    {
        $rombelId = $request->input('rombel_id');
        
        if (!$rombelId) {
            return redirect()->back()->with('error', 'Rombel ID is required.');
        }

        $rombel = Rombel::with(['cohorts' => function($q) {
            $q->where('status', 'active')->with('student');
        }, 'academicYear'])->findOrFail($rombelId);

        $students = $rombel->cohorts->pluck('student')->sortBy('name');

        $pdf = Pdf::loadView('pdf.exam-cards', [
            'rombel' => $rombel,
            'students' => $students,
            'madrasahName' => env('MADRASAH_NAME', 'MTs Negeri Contoh'),
            'examName' => $request->input('exam_name', 'Ujian Akhir Semester'),
        ]);

        // A4 paper, portrait
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("Kartu_Ujian_{$rombel->name}.pdf");
    }

    /**
     * Show Seat Layout Builder (Denah Kelas)
     */
    public function seatLayout(Request $request)
    {
        $rombelId = $request->input('rombel_id');
        $rombels = Rombel::all();
        $students = [];
        $activeRombel = null;

        if ($rombelId) {
            $activeRombel = Rombel::find($rombelId);
            $students = Student::whereHas('cohorts', function ($q) use ($rombelId) {
                $q->where('rombel_id', $rombelId)->where('status', 'active');
            })->orderBy('name')->get();
        }

        return Inertia::render('Students/SeatLayout', [
            'rombels' => $rombels,
            'students' => $students,
            'activeRombel' => $activeRombel,
        ]);
    }
}
