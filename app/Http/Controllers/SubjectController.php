<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('MasterData/Subjects/Index', [
            'subjects' => Subject::query()->orderBy('grade_level')->orderBy('order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Subject::create($this->validatedAttributes($request));

        return back()->with('success', 'Mata pelajaran berhasil ditambahkan.');
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $subject->update($this->validatedAttributes($request, $subject));

        return back()->with('success', 'Mata pelajaran berhasil diperbarui.');
    }

    public function destroy(Subject $subject): RedirectResponse
    {
        if ($subject->grades()->exists()) {
            return back()->withErrors(['delete' => 'Mata pelajaran tidak dapat dihapus karena sudah digunakan pada data nilai.']);
        }

        $subject->delete();

        return back()->with('success', 'Mata pelajaran berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, ?Subject $subject = null): array
    {
        $subjectId = $subject?->id ?? 'NULL';

        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:subjects,code,'.$subjectId],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:umum,agama,mulok'],
            'grade_level' => ['required', 'integer', 'in:7,8,9'],
            'kkm' => ['required', 'integer', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
    }
}
