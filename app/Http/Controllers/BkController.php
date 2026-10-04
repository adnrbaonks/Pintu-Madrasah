<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\BkRecord;
use App\Models\Setting;
use App\Models\Student;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BkController extends Controller
{
    public function index(Request $request): Response
    {
        $activeYear = AcademicYear::active();

        $query = BkRecord::with(['student:id,name,nisn,grade_level', 'reporter:id,name'])
            ->latest('record_date')
            ->latest('id');

        if ($activeYear) {
            $query->where('academic_year_id', $activeYear->id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->whereHas('student', fn ($s) =>
                $s->where('name', 'like', "%{$q}%")->orWhere('nisn', 'like', "%{$q}%"));
        }

        // Rekap poin per siswa (tahun aktif): negatif = pelanggaran
        $rekap = [];
        if ($activeYear) {
            $rekap = BkRecord::with('student:id,name,nisn,grade_level')
                ->where('academic_year_id', $activeYear->id)
                ->selectRaw('student_id, SUM(points) as total_points, COUNT(*) as total_records')
                ->groupBy('student_id')
                ->havingRaw('SUM(points) != 0')
                ->orderByRaw('SUM(points) ASC')
                ->get()
                ->map(fn ($r) => [
                    'student_id' => $r->student_id,
                    'name' => $r->student?->name ?? '-',
                    'nisn' => $r->student?->nisn ?? '-',
                    'grade_level' => $r->student?->grade_level ?? '-',
                    'total_points' => (int) $r->total_points,
                    'total_records' => (int) $r->total_records,
                ]);
        }

        return Inertia::render('Bk/Index', [
            'records' => $query->paginate(20)->withQueryString(),
            'rekap' => $rekap,
            'filters' => $request->only(['type', 'status', 'q']),
            'types' => BkRecord::TYPES,
            'tahunAjaran' => $activeYear?->full_label ?? '—',
            'studentOptions' => Student::where('status', 'active')
                ->orderBy('name')->limit(500)
                ->get(['id', 'name', 'nisn']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|uuid|exists:students,id',
            'record_date' => 'required|date',
            'type' => 'required|in:violation,habit,achievement,home_visit,summon',
            'category' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'points' => 'nullable|integer|between:-100,100',
            'follow_up' => 'nullable|string',
            'status' => 'nullable|in:open,followup,closed',
        ]);

        $data['points'] ??= $data['type'] === 'violation' ? -10 : 0;
        $data['status'] ??= 'open';
        $data['academic_year_id'] = AcademicYear::active()?->id;
        $data['reported_by'] = auth()->id();

        $record = BkRecord::create($data);

        return redirect()->back()->with('success', "Catatan BK untuk {$record->student->name} tersimpan.");
    }

    public function update(Request $request, BkRecord $bk)
    {
        $data = $request->validate([
            'record_date' => 'sometimes|date',
            'category' => 'sometimes|string|max:100',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'points' => 'nullable|integer|between:-100,100',
            'follow_up' => 'nullable|string',
            'status' => 'sometimes|in:open,followup,closed',
        ]);

        $bk->update($data);

        return redirect()->back()->with('success', 'Catatan BK diperbarui.');
    }

    public function destroy(BkRecord $bk)
    {
        $bk->delete();

        return redirect()->back()->with('success', 'Catatan BK dihapus.');
    }

    /** Cetak surat pemanggilan orang tua (PDF). */
    public function printSummon(BkRecord $bk)
    {
        $bk->load(['student', 'academicYear']);

        $pdf = Pdf::loadView('pdf.summon-letter', [
            'record' => $bk,
            'madrasahName' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs')),
            'madrasahAddress' => Setting::get('school_address', env('MADRASAH_ADDRESS', '')),
            'nsm' => Setting::get('nsm', ''),
            'headmaster' => Setting::get('headmaster_name', ''),
            'headmasterNip' => Setting::get('headmaster_nip', ''),
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("Panggilan_OT_{$bk->student->nisn}.pdf");
    }
}
