<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolClassController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('MasterData/Classes/Index', [
            'classes' => SchoolClass::query()
                ->withCount('rombels')
                ->orderBy('grade_level')
                ->orderBy('major_name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SchoolClass::create($this->validatedAttributes($request));

        return back()->with('success', 'Kelas berhasil ditambahkan.');
    }

    public function update(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $schoolClass->update($this->validatedAttributes($request, $schoolClass));

        return back()->with('success', 'Kelas berhasil diperbarui.');
    }

    public function destroy(SchoolClass $schoolClass): RedirectResponse
    {
        if ($schoolClass->rombels()->exists()) {
            return back()->withErrors(['delete' => 'Kelas tidak dapat dihapus karena masih digunakan oleh rombel.']);
        }

        $schoolClass->delete();

        return back()->with('success', 'Kelas berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, ?SchoolClass $schoolClass = null): array
    {
        $validated = $request->validate([
            'grade_level' => ['required', 'in:7,8,9'],
            'major_name' => ['nullable', 'string', 'max:100'],
        ]);

        $duplicateQuery = SchoolClass::query()
            ->where('grade_level', $validated['grade_level'])
            ->when(
                filled($validated['major_name'] ?? null),
                fn ($query) => $query->where('major_name', $validated['major_name']),
                fn ($query) => $query->whereNull('major_name'),
            );

        if ($schoolClass) {
            $duplicateQuery->whereKeyNot($schoolClass->id);
        }

        if ($duplicateQuery->exists()) {
            abort(422, 'Kombinasi tingkat dan jurusan sudah terdaftar.');
        }

        return $validated;
    }
}
