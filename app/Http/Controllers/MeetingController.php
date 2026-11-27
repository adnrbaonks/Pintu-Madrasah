<?php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingMinute;
use App\Models\MeetingMinuteRevision;
use App\Models\MeetingParticipant;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    public function __construct(
        private ArchiveService $archiveService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isManager = $user && $user->can('view_meetings');

        $query = Meeting::query()
            ->with([
                'organizationUnit:id,code,name',
                'leader:id,name',
                'notetaker:id,name',
            ])
            ->withCount(['participants', 'actions'])
            ->orderByDesc('scheduled_at');

        if (! $isManager && $user) {
            $query->where(function ($q) use ($user): void {
                $q->where('leader_user_id', $user->id)
                    ->orWhere('notetaker_user_id', $user->id)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('type')) {
            $query->where('meeting_type', (string) $request->query('type'));
        }

        $meetings = $query->limit(100)->get();

        return Inertia::render('Meetings/Index', [
            'meetings' => $meetings,
            'units' => OrganizationUnit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'filters' => [
                'status' => (string) $request->query('status', ''),
                'type' => (string) $request->query('type', ''),
            ],
            'stats' => [
                'total' => $meetings->count(),
                'scheduled' => $meetings->where('status', Meeting::STATUS_SCHEDULED)->count(),
                'completed' => $meetings->where('status', Meeting::STATUS_COMPLETED)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $canManage = $user && $user->can('manage_meetings');
        if (! $canManage) {
            abort(403, 'Anda tidak memiliki hak akses untuk menjadwalkan rapat dinas.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'meeting_type' => 'required|in:dinas,wali_murid,komite,mgmp,evaluasi',
            'scheduled_at' => 'required|date',
            'ended_at' => 'nullable|date|after_or_equal:scheduled_at',
            'location' => 'required|string|max:255',
            'is_online' => 'sometimes|boolean',
            'online_meeting_url' => 'nullable|url|max:500',
            'organization_unit_id' => 'nullable|uuid|exists:org_units,id',
            'leader_user_id' => 'nullable|uuid|exists:users,id',
            'notetaker_user_id' => 'nullable|uuid|exists:users,id',
            'agenda' => 'nullable|string|max:4000',
            'participant_ids' => 'nullable|array',
            'participant_ids.*' => 'uuid|exists:users,id',
        ]);

        $meeting = DB::transaction(function () use ($validated, $user): Meeting {
            $meetingNumber = sprintf('NOT/%s/%04d', now()->format('Y'), Meeting::withTrashed()->count() + 1);

            $meeting = Meeting::create([
                'meeting_number' => $meetingNumber,
                'title' => $validated['title'],
                'meeting_type' => $validated['meeting_type'],
                'scheduled_at' => $validated['scheduled_at'],
                'ended_at' => $validated['ended_at'] ?? null,
                'location' => $validated['location'],
                'is_online' => (bool) ($validated['is_online'] ?? false),
                'online_meeting_url' => $validated['online_meeting_url'] ?? null,
                'organization_unit_id' => $validated['organization_unit_id'] ?? null,
                'leader_user_id' => $validated['leader_user_id'] ?? $user->id,
                'notetaker_user_id' => $validated['notetaker_user_id'] ?? $user->id,
                'agenda' => $validated['agenda'] ?? null,
                'status' => Meeting::STATUS_SCHEDULED,
                'created_by' => $user->id,
            ]);

            // Sinkronkan ke Kalender Madrasah
            CalendarEvent::create([
                'title' => 'Rapat: '.$meeting->title,
                'description' => $meeting->agenda,
                'event_type' => 'meeting',
                'start_time' => $meeting->scheduled_at,
                'end_time' => $meeting->ended_at,
                'color' => '#8b5cf6',
                'meeting_id' => $meeting->id,
                'created_by' => $user->id,
            ]);

            // Daftarkan pimpinan, notulis, dan peserta
            $participants = collect($validated['participant_ids'] ?? [])
                ->push($meeting->leader_user_id)
                ->push($meeting->notetaker_user_id)
                ->filter()
                ->unique();

            foreach ($participants as $pId) {
                $role = match ($pId) {
                    $meeting->leader_user_id => 'leader',
                    $meeting->notetaker_user_id => 'notetaker',
                    default => 'participant',
                };

                MeetingParticipant::firstOrCreate(
                    ['meeting_id' => $meeting->id, 'user_id' => $pId],
                    ['role_in_meeting' => $role, 'attendance_status' => 'invited']
                );
            }

            return $meeting;
        });

        return redirect()
            ->route('meetings.show', $meeting->id)
            ->with('success', "Rapat {$meeting->meeting_number} berhasil dijadwalkan dan disinkronkan ke Kalender.");
    }

    public function show(Request $request, Meeting $meeting): Response
    {
        $user = $request->user();
        $isManager = $user && $user->can('view_meetings');
        abort_unless(
            $meeting->participants()->where('user_id', $user->id)->exists()
                || $meeting->leader_user_id === $user->id
                || $meeting->notetaker_user_id === $user->id
                || $isManager,
            403,
            'Anda bukan peserta atau pengelola rapat ini.'
        );

        $meeting->load([
            'organizationUnit:id,code,name',
            'leader:id,name',
            'notetaker:id,name',
            'participants.user:id,name,role',
            'minutes.recorder:id,name',
            'minutes.approver:id,name',
            'minutes.revisions.reviser:id,name',
            'minutes.archive:id,archive_number,title,visibility,allowed_roles,created_by,owner_user_id,owner_staff_id',
            'actions.assignee:id,name',
        ]);

        if ($user && $meeting->relationLoaded('minutes')) {
            $this->archiveService->hideUnviewableRelation($user, $meeting->minutes);
        }

        return Inertia::render('Meetings/Show', [
            'meeting' => $meeting,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'archives' => $this->archiveService->recentViewable($user, 50),
        ]);
    }

    /**
     * Catat absensi kehadiran peserta rapat.
     */
    public function markAttendance(Request $request, Meeting $meeting): RedirectResponse
    {
        $user = $request->user();
        $isMeetingManager = $user && $user->can('manage_meetings');
        $isLeader = $meeting->leader_user_id === $user->id;

        if (! $isMeetingManager && ! $isLeader) {
            abort(403, 'Hanya pengelola atau pimpinan rapat yang dapat mencatat kehadiran.');
        }

        $validated = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'attendance_status' => 'required|in:present,absent,excused',
            'absence_reason' => 'nullable|string|max:500',
        ]);

        abort_unless($meeting->participants()->where('user_id', $validated['user_id'])->exists(), 422, 'Pengguna bukan peserta rapat.');

        MeetingParticipant::updateOrCreate(
            ['meeting_id' => $meeting->id, 'user_id' => $validated['user_id']],
            [
                'attendance_status' => $validated['attendance_status'],
                'attended_at' => $validated['attendance_status'] === 'present' ? now() : null,
                'absence_reason' => $validated['absence_reason'] ?? null,
            ]
        );

        return redirect()->back()->with('success', 'Kehadiran peserta diperbarui.');
    }

    /**
     * Simpan notulen & keputusan rapat (dapat ditautkan langsung ke Arsip Digital).
     * Notulen yang telah disahkan (approved_at !== null) terkunci dari timpa langsung
     * dan wajib melalui mekanisme revisi eksplisit yang mencatat versi sebelumnya.
     */
    public function saveMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $user = $request->user();
        $isManager = $user && $user->can('manage_meetings');
        if (! $isManager && $meeting->notetaker_user_id !== $user->id) {
            abort(403, 'Hanya notulis atau pengelola rapat yang dapat menyimpan notulen.');
        }

        $validated = $request->validate([
            'discussion_summary' => 'required|string|max:10000',
            'decisions' => 'required|string|max:5000',
            'archive_id' => 'nullable|uuid|exists:archives,id',
            'revision_notes' => 'nullable|string|max:1000',
        ]);

        if (! empty($validated['archive_id'])) {
            $archive = Archive::findOrFail($validated['archive_id']);
            if (! $this->archiveService->canView($user, $archive)) {
                abort(403, 'Anda tidak memiliki hak akses untuk menautkan arsip ini pada notulen rapat.');
            }
        }

        $revisionNotes = trim((string) ($validated['revision_notes'] ?? ''));

        $result = DB::transaction(function () use ($meeting, $user, $validated, $revisionNotes): string {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $existingMinute = MeetingMinute::query()
                ->where('meeting_id', $lockedMeeting->id)
                ->lockForUpdate()
                ->first();

            if ($existingMinute !== null && $existingMinute->approved_at !== null) {
                if ($revisionNotes === '') {
                    return 'locked_error';
                }

                $this->recordLockedMinuteRevision($lockedMeeting, $existingMinute, $user, $validated, $revisionNotes);

                return 'revised';
            }

            MeetingMinute::updateOrCreate(
                ['meeting_id' => $lockedMeeting->id],
                [
                    'discussion_summary' => $validated['discussion_summary'],
                    'decisions' => $validated['decisions'],
                    'archive_id' => $validated['archive_id'] ?? null,
                    'recorded_by' => $user->id,
                ]
            );

            return 'saved';
        });

        if ($result === 'locked_error') {
            return redirect()->back()->withErrors([
                'minutes' => 'Notulen rapat telah disahkan dan terkunci. Gunakan mekanisme revisi dengan mengisi alasan/catatan revisi (revision_notes).',
            ]);
        }

        if ($result === 'revised') {
            return redirect()->back()->with('success', 'Revisi notulen rapat disimpan. Pengesahan sebelumnya diarsipkan dan memerlukan pengesahan ulang.');
        }

        return redirect()->back()->with('success', 'Notulen rapat berhasil disimpan.');
    }

    /**
     * Revisi eksplisit notulen rapat yang telah disahkan: menyimpan snapshot versi sebelumnya,
     * alasan perubahan, serta mereset status pengesahan agar disahkan ulang.
     */
    public function reviseMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $user = $request->user();
        $isManager = $user && $user->can('manage_meetings');
        if (! $isManager && $meeting->notetaker_user_id !== $user->id) {
            abort(403, 'Hanya notulis atau pengelola rapat yang dapat merevisi notulen.');
        }

        $validated = $request->validate([
            'discussion_summary' => 'required|string|max:10000',
            'decisions' => 'required|string|max:5000',
            'archive_id' => 'nullable|uuid|exists:archives,id',
            'revision_notes' => 'required|string|min:5|max:1000',
        ]);

        if (! empty($validated['archive_id'])) {
            $archive = Archive::findOrFail($validated['archive_id']);
            if (! $this->archiveService->canView($user, $archive)) {
                abort(403, 'Anda tidak memiliki hak akses untuk menautkan arsip ini pada notulen rapat.');
            }
        }

        $minute = $meeting->minutes()->firstOrFail();

        return $this->applyMinuteRevision($meeting, $minute, $user, $validated, trim($validated['revision_notes']));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyMinuteRevision(
        Meeting $meeting,
        MeetingMinute $minute,
        User $user,
        array $validated,
        string $revisionNotes
    ): RedirectResponse {
        DB::transaction(function () use ($meeting, $minute, $user, $validated, $revisionNotes): void {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $lockedMinute = MeetingMinute::query()->whereKey($minute->id)->lockForUpdate()->firstOrFail();

            $this->recordLockedMinuteRevision($lockedMeeting, $lockedMinute, $user, $validated, $revisionNotes);
        });

        return redirect()->back()->with('success', 'Revisi notulen rapat disimpan. Pengesahan sebelumnya diarsipkan dan memerlukan pengesahan ulang.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function recordLockedMinuteRevision(
        Meeting $lockedMeeting,
        MeetingMinute $lockedMinute,
        User $user,
        array $validated,
        string $revisionNotes
    ): void {
        $nextVersion = ((int) $lockedMinute->revisions()->max('version_number')) + 1;

        MeetingMinuteRevision::create([
            'meeting_minute_id' => $lockedMinute->id,
            'version_number' => $nextVersion,
            'previous_discussion_summary' => $lockedMinute->discussion_summary,
            'previous_decisions' => $lockedMinute->decisions,
            'previous_archive_id' => $lockedMinute->archive_id,
            'previous_approved_by' => $lockedMinute->approved_by,
            'previous_approved_at' => $lockedMinute->approved_at,
            'revision_notes' => $revisionNotes,
            'revised_by' => $user->id,
        ]);

        $lockedMinute->update([
            'discussion_summary' => $validated['discussion_summary'],
            'decisions' => $validated['decisions'],
            'archive_id' => $validated['archive_id'] ?? null,
            'recorded_by' => $user->id,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        if ($lockedMeeting->status === Meeting::STATUS_COMPLETED) {
            $lockedMeeting->update(['status' => Meeting::STATUS_ONGOING]);
        }
    }

    /**
     * Pengesahan notulen rapat oleh Kepala Madrasah / Pimpinan Rapat secara atomik dengan row lock.
     */
    public function approveMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $user = $request->user();
        $canApprove = ($user && $user->can('approve_meeting_minutes'))
            || $user->id === $meeting->leader_user_id;

        if (! $canApprove) {
            abort(403, 'Hanya Pimpinan Rapat, Kepala Madrasah, atau Admin yang dapat mengesahkan notulen.');
        }

        $approvalError = DB::transaction(function () use ($meeting, $user): ?string {
            $lockedMeeting = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $lockedMinute = MeetingMinute::query()
                ->where('meeting_id', $lockedMeeting->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMinute->approved_at !== null) {
                return 'Notulen rapat sudah disahkan sebelumnya.';
            }

            $lockedMinute->update([
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            $lockedMeeting->update(['status' => Meeting::STATUS_COMPLETED]);

            return null;
        });

        if ($approvalError !== null) {
            return redirect()->back()->withErrors(['minutes' => $approvalError]);
        }

        return redirect()->back()->with('success', 'Notulen rapat disahkan dan status rapat selesai.');
    }

    /**
     * Tambah tindak lanjut / action item pasca rapat.
     */
    public function storeAction(Request $request, Meeting $meeting): RedirectResponse
    {
        $user = $request->user();
        $isManager = $user && $user->can('manage_meetings');
        if (! $isManager && $meeting->leader_user_id !== $user->id) {
            abort(403, 'Hanya pimpinan atau pengelola rapat yang dapat menetapkan tindak lanjut.');
        }

        $validated = $request->validate([
            'assigned_to_user_id' => 'nullable|uuid|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
        ]);

        if (! empty($validated['assigned_to_user_id'])) {
            $isParticipant = $meeting->participants()->where('user_id', $validated['assigned_to_user_id'])->exists()
                || $meeting->leader_user_id === $validated['assigned_to_user_id']
                || $meeting->notetaker_user_id === $validated['assigned_to_user_id'];
            if (! $isParticipant) {
                throw ValidationException::withMessages([
                    'assigned_to_user_id' => 'Penerima tugas harus terdaftar sebagai peserta atau pimpinan rapat.',
                ]);
            }
        }

        MeetingAction::create([
            'meeting_id' => $meeting->id,
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'status' => MeetingAction::STATUS_PENDING,
        ]);

        return redirect()->back()->with('success', 'Tindak lanjut (action item) ditambahkan.');
    }

    /**
     * Update status tindak lanjut rapat (in_progress, completed).
     */
    public function updateAction(Request $request, MeetingAction $action): RedirectResponse
    {
        $user = $request->user();
        $isManager = $user && $user->can('manage_meetings');
        $isLeader = $action->meeting && $action->meeting->leader_user_id === $user->id;
        $isAssignee = $action->assigned_to_user_id === $user->id;

        if (! $isManager && ! $isLeader && ! $isAssignee) {
            abort(403, 'Hanya petugas yang ditugaskan, pimpinan rapat, atau pengelola yang dapat memperbarui tindak lanjut.');
        }

        $validated = $request->validate([
            'status' => 'required|in:pending,in_progress,completed,overdue',
            'resolution_notes' => 'nullable|string|max:1000',
        ]);

        $updates = [
            'status' => $validated['status'],
            'resolution_notes' => $validated['resolution_notes'] ?? $action->resolution_notes,
        ];

        if ($validated['status'] === MeetingAction::STATUS_COMPLETED) {
            $updates['completed_at'] = now();
        }

        $action->update($updates);

        return redirect()->back()->with('success', 'Status tindak lanjut diperbarui.');
    }
}
