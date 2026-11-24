<?php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingMinute;
use App\Models\MeetingParticipant;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
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
            'minutes.archive:id,archive_number,title',
            'actions.assignee:id,name',
        ]);

        return Inertia::render('Meetings/Show', [
            'meeting' => $meeting,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'archives' => Archive::orderByDesc('created_at')->limit(50)->get(['id', 'archive_number', 'title']),
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
        ]);

        MeetingMinute::updateOrCreate(
            ['meeting_id' => $meeting->id],
            [
                'discussion_summary' => $validated['discussion_summary'],
                'decisions' => $validated['decisions'],
                'archive_id' => $validated['archive_id'] ?? null,
                'recorded_by' => $user->id,
            ]
        );

        return redirect()->back()->with('success', 'Notulen rapat berhasil disimpan.');
    }

    /**
     * Pengesahan notulen rapat oleh Kepala Madrasah / Pimpinan Rapat.
     */
    public function approveMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $user = $request->user();
        $canApprove = ($user && $user->can('approve_meeting_minutes'))
            || $user->id === $meeting->leader_user_id;

        if (! $canApprove) {
            abort(403, 'Hanya Pimpinan Rapat, Kepala Madrasah, atau Admin yang dapat mengesahkan notulen.');
        }

        $minute = $meeting->minutes()->firstOrFail();
        $minute->update([
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $meeting->update(['status' => Meeting::STATUS_COMPLETED]);

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
