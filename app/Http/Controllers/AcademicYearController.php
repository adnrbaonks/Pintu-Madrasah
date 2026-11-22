<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AcademicYearController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('MasterData/AcademicYears/Index', [
            'academicYears' => AcademicYear::query()
                ->withCount(['rombels', 'cohorts'])
                ->orderByDesc('year')
                ->orderByDesc('semester')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        AcademicYear::create($this->validatedAttributes($request));

        return back()->with('success', 'Tahun ajaran berhasil ditambahkan.');
    }

    public function update(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        if ($academicYear->is_locked) {
            return back()->withErrors(['academic_year' => 'Tahun ajaran yang terkunci tidak dapat diubah.']);
        }

        $academicYear->update($this->validatedAttributes($request, $academicYear));

        return back()->with('success', 'Tahun ajaran berhasil diperbarui.');
    }

    public function setActive(AcademicYear $academicYear): RedirectResponse
    {
        DB::transaction(function () use ($academicYear): void {
            AcademicYear::query()->where('is_active', true)->update(['is_active' => false]);
            $academicYear->update(['is_active' => true]);
        });

        return back()->with('success', 'Tahun ajaran aktif berhasil diubah.');
    }

    public function toggleLock(AcademicYear $academicYear): RedirectResponse
    {
        $academicYear->update(['is_locked' => ! $academicYear->is_locked]);

        return back()->with('success', $academicYear->is_locked
            ? 'Tahun ajaran berhasil dikunci.'
            : 'Tahun ajaran berhasil dibuka kuncinya.');
    }

    public function destroy(AcademicYear $academicYear): RedirectResponse
    {
        if ($academicYear->is_active) {
            return back()->withErrors(['delete' => 'Tahun ajaran aktif tidak dapat dihapus.']);
        }

        if ($academicYear->rombels()->exists() || $academicYear->cohorts()->exists()) {
            return back()->withErrors(['delete' => 'Tahun ajaran tidak dapat dihapus karena sudah digunakan oleh rombel atau riwayat siswa.']);
        }

        $academicYear->delete();

        return back()->with('success', 'Tahun ajaran berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, ?AcademicYear $academicYear = null): array
    {
        return $request->validate([
            'year' => ['required', 'string', 'regex:/^\d{4}\/\d{4}$/'],
            'semester' => [
                'required',
                Rule::in(['Ganjil', 'Genap']),
                Rule::unique('academic_years', 'semester')
                    ->where(fn ($query) => $query->where('year', $request->input('year')))
                    ->ignore($academicYear),
            ],
            'is_locked' => ['sometimes', 'boolean'],
        ]);
    }
}
