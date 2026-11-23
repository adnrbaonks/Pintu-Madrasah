<?php

namespace App\Http\Controllers;

use App\Models\StaffChangeRequest;
use App\Services\StaffChangeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffApprovalController extends Controller
{
    public function __construct(
        private StaffChangeRequestService $changeRequestService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeApprover($request);

        $statusFilter = $request->input('status');

        $requests = StaffChangeRequest::query()
            ->with([
                'staff:id,full_name_with_titles,nip,nuptk,employment_status',
                'requester:id,name,username,role',
                'items',
                'attachments',
                'approvals.actor:id,name,username,role',
                'histories',
            ])
            ->whereIn('status', [
                StaffChangeRequest::STATUS_VERIFIED,
                StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL,
                StaffChangeRequest::STATUS_APPROVED,
                StaffChangeRequest::STATUS_REJECTED,
                StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC,
                StaffChangeRequest::STATUS_COMPLETED,
            ])
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->orderByRaw("CASE WHEN status IN ('verified', 'pending_head_approval') THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Approvals/Head/Index', [
            'changeRequests' => $requests,
            'filters' => [
                'status' => $statusFilter,
            ],
            'ownership' => config('data_ownership.staff', []),
        ]);
    }

    public function show(Request $request, StaffChangeRequest $changeRequest): Response
    {
        $this->authorizeApprover($request);

        $changeRequest->load([
            'staff:id,full_name_with_titles,nip,nuptk,employment_status',
            'requester:id,name,username,role',
            'items',
            'attachments',
            'approvals.actor:id,name,username,role',
            'histories',
        ]);

        return Inertia::render('Approvals/Head/Show', [
            'changeRequest' => $changeRequest,
            'ownership' => config('data_ownership.staff', []),
        ]);
    }

    public function approve(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeApprover($request);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->changeRequestService->approveByHead(
            changeRequest: $changeRequest,
            actor: $request->user(),
            notes: $validated['notes'] ?? null,
        );

        return back()->with('success', 'Pengajuan perubahan data berhasil disetujui dan diterapkan.');
    }

    public function revision(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeApprover($request);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $this->changeRequestService->requestRevision(
            changeRequest: $changeRequest,
            actor: $request->user(),
            notes: $validated['notes'],
        );

        return back()->with('success', 'Permintaan revisi berhasil dikirim kembali ke pemohon.');
    }

    public function reject(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeApprover($request);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $this->changeRequestService->rejectByHead(
            changeRequest: $changeRequest,
            actor: $request->user(),
            notes: $validated['notes'],
        );

        return back()->with('success', 'Pengajuan perubahan data ditolak oleh Kepala Madrasah.');
    }

    private function authorizeApprover(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('admin') && ! $user->hasRole('kepala_madrasah')) {
            abort(403, 'Admin teknis tidak berwenang menyetujui pengajuan perubahan data GTK.');
        }

        if (! $user->hasRole('kepala_madrasah') && ! $user->can('approve_staff_changes')) {
            abort(403, 'Hanya Kepala Madrasah yang memiliki kewenangan menyetujui perubahan data GTK.');
        }
    }
}
