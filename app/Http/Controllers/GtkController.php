<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStaffRequest;
use App\Models\Staff;
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
     * Fallback / Backward Compatibility:
     * Delegasikan langsung ke StaffController kanonik.
     */
    public function show(Staff $staff, StaffController $staffController): Response
    {
        return $staffController->show($staff);
    }

    public function edit(Staff $staff, StaffController $staffController): Response
    {
        return $staffController->edit($staff);
    }

    public function update(UpdateStaffRequest $request, Staff $staff, StaffController $staffController): RedirectResponse
    {
        return $staffController->update($request, $staff);
    }
}
