<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Services\DataOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GtkController extends Controller
{
    /**
     * Tampilkan daftar GTK (Guru dan Tenaga Kependidikan).
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $query = Staff::query()
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('full_name_with_titles', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->orderBy('is_active', 'desc')
            ->orderBy('full_name_with_titles', 'asc');

        $staff = $query->paginate(15)->withQueryString();

        return Inertia::render('Gtk/Index', [
            'staff' => $staff,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    /**
     * Tampilkan profil detail satu pegawai GTK.
     */
    public function show(Staff $staff): Response
    {
        $staff->load([
            'user:id,staff_id,username,role,is_active',
            'homeroomRombels.academicYear',
            'homeroomRombels.schoolClass',
        ]);

        $ownership = config('data_ownership.staff', []);

        return Inertia::render('Gtk/Show', [
            'staff' => $staff,
            'ownership' => $ownership,
        ]);
    }

    /**
     * Tampilkan formulir edit pegawai GTK dengan penandaan field terproteksi.
     */
    public function edit(Staff $staff, DataOwnershipService $ownershipService): Response
    {
        return Inertia::render('Gtk/Edit', [
            'staff' => $staff,
            'ownership' => config('data_ownership.staff', []),
            'editableFields' => $ownershipService->getEditableFields('staff'),
            'readOnlyFields' => $ownershipService->getReadOnlyFields('staff'),
        ]);
    }

    /**
     * Simpan perubahan profil pegawai GTK dengan memfilter field read-only.
     */
    public function update(Request $request, Staff $staff, DataOwnershipService $ownershipService): RedirectResponse
    {
        $validated = $request->validate([
            'full_name_with_titles' => ['required', 'string', 'max:255'],
            'first_title' => ['nullable', 'string', 'max:50'],
            'last_title' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'employment_status' => ['required', 'string', 'max:50'],
            'functional_position' => ['nullable', 'string', 'max:100'],
            'highest_education' => ['nullable', 'string', 'max:50'],
            'university_name' => ['nullable', 'string', 'max:255'],
            'study_program' => ['nullable', 'string', 'max:150'],
            'graduation_year' => ['nullable', 'integer', 'min:1950', 'max:2099'],
            'is_active' => ['boolean'],
        ]);

        // Pastikan hanya atribut yang diizinkan oleh DataOwnershipService yang disimpan
        $filtered = $ownershipService->filterAllowedAttributes('staff', $validated);

        $staff->update($filtered);

        return redirect()->route('gtk.show', $staff->id)
            ->with('success', 'Data pegawai GTK berhasil diperbarui.');
    }
}
