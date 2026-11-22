<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStaffRequest;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class GtkController extends Controller
{
    /**
     * Fallback / Backward Compatibility:
     * Delegasikan langsung ke StaffController kanonik.
     */
    public function index(Request $request, StaffController $staffController): Response
    {
        return $staffController->index($request);
    }

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
