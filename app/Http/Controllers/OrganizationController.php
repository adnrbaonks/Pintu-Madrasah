<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\Staff;
use App\Services\OrganizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function __construct(
        private OrganizationService $organizationService,
    ) {}

    public function index(Request $request): Response
    {
        $dateInput = $request->input('date');
        $effectiveDate = $dateInput
            ? Carbon::parse($dateInput)->toDateString()
            : now()->toDateString();

        $headApprover = $this->organizationService->resolveApproverByLevel(1, null, $effectiveDate);
        $tuApprover = $this->organizationService->resolveApproverByLevel(2, null, $effectiveDate);

        return Inertia::render('Organization/Index', [
            'effectiveDate' => $effectiveDate,
            'units' => $this->organizationService->getUnitTree(),
            'positions' => Position::query()
                ->with('unit:id,code,name,type')
                ->withCount('assignments')
                ->orderBy('level')
                ->orderBy('code')
                ->get(),
            'assignments' => PositionAssignment::query()
                ->with([
                    'staff:id,full_name_with_titles,nip,nuptk,employment_status',
                    'position:id,org_unit_id,code,name,level,is_structural',
                    'unit:id,code,name,type',
                ])
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('is_primary')
                ->orderByDesc('valid_from')
                ->get(),
            'effectiveAssignments' => $this->organizationService->getEffectiveAssignments($effectiveDate),
            'approvers' => [
                'head_approval' => $headApprover ? [
                    'id' => $headApprover->id,
                    'full_name_with_titles' => $headApprover->full_name_with_titles,
                    'nip' => $headApprover->nip,
                ] : null,
                'tu_review' => $tuApprover ? [
                    'id' => $tuApprover->id,
                    'full_name_with_titles' => $tuApprover->full_name_with_titles,
                    'nip' => $tuApprover->nip,
                ] : null,
            ],
            'staffList' => Staff::query()
                ->where('is_active', true)
                ->orderBy('full_name_with_titles')
                ->get(['id', 'full_name_with_titles', 'nip', 'nuptk', 'employment_status']),
        ]);
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $validated = $this->validateUnit($request);

        $unit = OrganizationUnit::create($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'created_organization_unit',
            'model_type' => OrganizationUnit::class,
            'model_id' => $unit->id,
            'before_payload' => null,
            'after_payload' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Unit organisasi berhasil ditambahkan.');
    }

    public function updateUnit(Request $request, OrganizationUnit $unit): RedirectResponse
    {
        $validated = $this->validateUnit($request, $unit);

        if (! empty($validated['parent_id']) && $validated['parent_id'] === $unit->id) {
            return back()->withErrors(['parent_id' => 'Unit kerja tidak boleh menjadi induk bagi dirinya sendiri.']);
        }

        $before = $unit->only(array_keys($validated));
        $unit->update($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'updated_organization_unit',
            'model_type' => OrganizationUnit::class,
            'model_id' => $unit->id,
            'before_payload' => $before,
            'after_payload' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Unit organisasi berhasil diperbarui.');
    }

    public function destroyUnit(OrganizationUnit $unit): RedirectResponse
    {
        if ($unit->children()->exists() || $unit->positions()->exists() || $unit->assignments()->exists()) {
            return back()->withErrors([
                'delete' => 'Unit kerja tidak dapat dihapus karena masih memiliki sub-unit, jabatan, atau riwayat penugasan.',
            ]);
        }

        $unit->delete();

        return back()->with('success', 'Unit organisasi berhasil dihapus.');
    }

    public function storePosition(Request $request): RedirectResponse
    {
        $validated = $this->validatePosition($request);

        $position = Position::create($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'created_position',
            'model_type' => Position::class,
            'model_id' => $position->id,
            'before_payload' => null,
            'after_payload' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Jabatan berhasil ditambahkan.');
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        $validated = $this->validatePosition($request, $position);
        $before = $position->only(array_keys($validated));

        $position->update($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'updated_position',
            'model_type' => Position::class,
            'model_id' => $position->id,
            'before_payload' => $before,
            'after_payload' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Jabatan berhasil diperbarui.');
    }

    public function destroyPosition(Position $position): RedirectResponse
    {
        if ($position->assignments()->exists()) {
            return back()->withErrors([
                'delete' => 'Jabatan tidak dapat dihapus karena sudah digunakan pada riwayat penugasan pegawai.',
            ]);
        }

        $position->delete();

        return back()->with('success', 'Jabatan berhasil dihapus.');
    }

    public function storeAssignment(Request $request): RedirectResponse
    {
        $validated = $this->validateAssignment($request);

        $this->organizationService->assignPosition($validated, null, auth()->id());

        return back()->with('success', 'Penugasan jabatan berhasil disimpan.');
    }

    public function updateAssignment(Request $request, PositionAssignment $assignment): RedirectResponse
    {
        $validated = $this->validateAssignment($request);

        $this->organizationService->assignPosition($validated, $assignment, auth()->id());

        return back()->with('success', 'Penugasan jabatan berhasil diperbarui.');
    }

    public function destroyAssignment(Request $request, PositionAssignment $assignment): RedirectResponse
    {
        $before = $assignment->toArray();
        $assignmentId = $assignment->id;
        $assignment->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'deleted_position_assignment',
            'model_type' => PositionAssignment::class,
            'model_id' => $assignmentId,
            'before_payload' => $before,
            'after_payload' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Penugasan jabatan berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUnit(Request $request, ?OrganizationUnit $unit = null): array
    {
        return $request->validate([
            'parent_id' => ['nullable', 'uuid', 'exists:org_units,id'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('org_units', 'code')->ignore($unit?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['pimpinan', 'tata_usaha', 'akademik', 'kesiswaan', 'kurikulum', 'sarana', 'unit'])],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePosition(Request $request, ?Position $position = null): array
    {
        return $request->validate([
            'org_unit_id' => ['required', 'uuid', 'exists:org_units,id'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('positions', 'code')->ignore($position?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', 'integer', 'min:1', 'max:4'],
            'is_structural' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAssignment(Request $request): array
    {
        return $request->validate([
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'position_id' => ['required', 'uuid', 'exists:positions,id'],
            'org_unit_id' => ['nullable', 'uuid', 'exists:org_units,id'],
            'sk_number' => ['nullable', 'string', 'max:100'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_primary' => ['required', 'boolean'],
            'status' => ['required', 'string', Rule::in(['active', 'ended', 'revoked'])],
        ]);
    }
}
