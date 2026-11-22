<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStaffRequest;
use App\Models\Staff;
use App\Services\DataOwnershipService;
use App\Services\StaffService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(
        private StaffService $staffService,
        private DataOwnershipService $dataOwnershipService,
    ) {}

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

    public function edit(Staff $staff): Response
    {
        return Inertia::render('Gtk/Edit', [
            'staff' => $staff,
            'ownership' => config('data_ownership.staff', []),
            'editableFields' => $this->dataOwnershipService->getEditableFields('staff'),
            'readOnlyFields' => $this->dataOwnershipService->getReadOnlyFields('staff'),
        ]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): RedirectResponse
    {
        $this->staffService->update($staff, $request->validated(), auth()->id());

        return redirect()->route('gtk.show', $staff->id)
            ->with('success', 'Data pegawai GTK berhasil diperbarui.');
    }
}
