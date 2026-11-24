<?php

namespace App\Http\Controllers;

use App\Models\StaffChangeRequest;
use App\Services\StaffChangeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffChangeRequestController extends Controller
{
    public function __construct(
        private StaffChangeRequestService $changeRequestService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $staff = $user->staff;

        $requests = StaffChangeRequest::query()
            ->with(['items', 'attachments', 'approvals.actor:id,name,username,role'])
            ->where(function ($query) use ($user) {
                $query->where('requester_user_id', $user->id);
                if ($user->staff_id) {
                    $query->orWhere('staff_id', $user->staff_id);
                }
            })
            ->latest()
            ->get();

        return Inertia::render('StaffSelf/ChangeRequests/Index', [
            'staff' => $staff,
            'changeRequests' => $requests,
            'ownership' => config('data_ownership.staff', []),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('StaffSelf/ChangeRequests/Create', [
            'staff' => $user->staff,
            'ownership' => config('data_ownership.staff', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $staff = $user->staff;

        if (! $staff) {
            return back()->withErrors(['staff' => 'Akun Anda belum terhubung dengan data pegawai GTK.']);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'changes' => ['required', 'array', 'min:1'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'submit_now' => ['nullable', 'boolean'],
        ]);

        $changeRequest = $this->changeRequestService->createDraft(
            staff: $staff,
            requester: $user,
            proposedChanges: $validated['changes'],
            reason: $validated['reason'],
            attachments: $request->file('attachments', []),
        );

        if (! empty($validated['submit_now'])) {
            $this->changeRequestService->submit($changeRequest, $user);

            return redirect()
                ->route('staff-self.requests.show', $changeRequest->id)
                ->with('success', 'Pengajuan perubahan data berhasil dikirim untuk diverifikasi Tata Usaha.');
        }

        return redirect()
            ->route('staff-self.requests.show', $changeRequest->id)
            ->with('success', 'Draf pengajuan perubahan data berhasil disimpan.');
    }

    public function show(Request $request, StaffChangeRequest $changeRequest): Response
    {
        $this->authorizeOwnerOrReviewer($request, $changeRequest);

        $changeRequest->load([
            'staff:id,full_name_with_titles,nip,nuptk,employment_status',
            'requester:id,name,username,role',
            'items',
            'attachments',
            'approvals.actor:id,name,username,role',
            'histories',
        ]);

        return Inertia::render('StaffSelf/ChangeRequests/Show', [
            'changeRequest' => $changeRequest,
            'ownership' => config('data_ownership.staff', []),
        ]);
    }

    public function update(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeOwner($request, $changeRequest);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'changes' => ['required', 'array', 'min:1'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'submit_now' => ['nullable', 'boolean'],
        ]);

        $updated = $this->changeRequestService->updateDraft(
            changeRequest: $changeRequest,
            actor: $request->user(),
            proposedChanges: $validated['changes'],
            reason: $validated['reason'],
            attachments: $request->file('attachments', []),
        );

        if (! empty($validated['submit_now'])) {
            $this->changeRequestService->submit($updated, $request->user());

            return redirect()
                ->route('staff-self.requests.show', $updated->id)
                ->with('success', 'Pengajuan hasil revisi berhasil dikirim kembali ke Tata Usaha.');
        }

        return back()->with('success', 'Draf pengajuan berhasil diperbarui.');
    }

    public function submit(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeOwner($request, $changeRequest);

        $this->changeRequestService->submit($changeRequest, $request->user());

        return back()->with('success', 'Pengajuan berhasil dikirim untuk diverifikasi Tata Usaha.');
    }

    public function cancel(Request $request, StaffChangeRequest $changeRequest): RedirectResponse
    {
        $this->authorizeOwner($request, $changeRequest);

        $this->changeRequestService->cancel($changeRequest, $request->user());

        return redirect()
            ->route('staff-self.requests.index')
            ->with('success', 'Pengajuan perubahan data berhasil dibatalkan.');
    }

    private function authorizeOwner(Request $request, StaffChangeRequest $changeRequest): void
    {
        $user = $request->user();

        if ($changeRequest->requester_user_id !== $user->id && (! $user->staff_id || $changeRequest->staff_id !== $user->staff_id)) {
            abort(403, 'Anda hanya dapat mengelola pengajuan milik Anda sendiri.');
        }
    }

    private function authorizeOwnerOrReviewer(Request $request, StaffChangeRequest $changeRequest): void
    {
        $user = $request->user();
        $isOwner = $changeRequest->requester_user_id === $user->id
            || ($user->staff_id && $changeRequest->staff_id === $user->staff_id);
        $isReviewer = $user->can('verify_staff_changes') || $user->can('approve_staff_changes');

        if (! $isOwner && ! $isReviewer) {
            abort(403, 'Anda tidak memiliki akses ke pengajuan ini.');
        }
    }
}
