<?php

namespace App\Http\Controllers;

use App\Models\StaffChangeRequest;
use App\Services\StaffChangeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffVerificationController extends Controller
{
    public function __construct(
        private StaffChangeRequestService $changeRequestService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeVerifier($request);

        $statusFilter = $request->input('status');

        $requests = StaffChangeRequest::query()
            ->with([
                'staff:id,full_name_with_titles,nip,nuptk,employment_status',
                'requester:id,name,username,role',
                'items',
                'attachments',
                'approvals.actor:id,name,username,role',
            ])
            ->where('status', '!=', StaffChangeRequest::STATUS_DRAFT)
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->orderByRaw("CASE WHEN status IN ('submitted', 'under_tu_review') THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Approvals/Tu/Index', [
            'changeRequests' => $requests,
            'filters' => [
                'status' => $statusFilter,
            ],
            'ownership' => config('data_ownership.staff', []),
        ]);
    }

    public function show(Request $request, StaffChangeRequest $changeRequest): Response
    {
        $this->authorizeVerifier($request);

        $changeRequest->load([
            'staff:id,full_name_with_titles,nip,nuptk,employment_status',
            'requester:id,name,username,role',
            'items',
            'attachments',
            'approvals.actor:id,name,username,role',
            'histories',
        ]);

        return Inertia::render('Approvals/Tu/Show', [
            'changeRequest' => $changeRequest,
            'ownership' => config('data_ownership.staff', []),
        ]);
    }

    public function verify(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeVerifier($request);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->changeRequestService->verifyByTu(
            changeRequest: $changeRequest,
            actor: $request->user(),
            notes: $validated['notes'] ?? null,
        );

        return back()->with('success', 'Pengajuan berhasil diverifikasi Tata Usaha dan diteruskan ke Kepala Madrasah.');
    }

    public function revision(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeVerifier($request);

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
        $this->authorizeVerifier($request);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $this->changeRequestService->rejectByTu(
            changeRequest: $changeRequest,
            actor: $request->user(),
            notes: $validated['notes'],
        );

        return back()->with('success', 'Pengajuan perubahan data ditolak pada tahap verifikasi Tata Usaha.');
    }

    private function authorizeVerifier(Request $request): void
    {
        $this->changeRequestService->authorizeTuReviewer($request->user());
    }
}
