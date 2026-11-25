<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Archive;
use App\Services\ArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function __construct(
        private ArchiveService $archiveService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Announcement::query()
            ->with(['author:id,name,role', 'archive:id,archive_number,title'])
            ->where('is_active', true)
            ->whereDate('publish_date', '<=', now()->toDateString())
            ->where(function ($q): void {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            });

        $isAdmin = $user && $user->can('view_all_announcements');

        if (! $isAdmin) {
            $userRoles = $user ? $user->roles()->pluck('name')->all() : [];
            $isGtk = ! empty($user->staff_id) || ($user && ($user->hasAnyRole(['kepala_madrasah', 'kaur_tu', 'staf', 'operator', 'guru', 'pegawai'])));

            $query->where(function ($scopeQuery) use ($user, $userRoles, $isGtk): void {
                $scopeQuery->where('author_user_id', $user->id)
                    ->orWhere('target_scope', 'all');

                if ($isGtk) {
                    $scopeQuery->orWhere('target_scope', 'gtk_only');
                }

                $scopeQuery->orWhere(function ($roleQuery) use ($userRoles): void {
                    $roleQuery->where('target_scope', 'role_specific')
                        ->where(function ($sub) use ($userRoles): void {
                            foreach ($userRoles as $role) {
                                $sub->orWhereJsonContains('target_roles', $role);
                            }
                        });
                });
            });
        }

        $announcements = $query->orderByDesc('is_pinned')
            ->orderByDesc('publish_date')
            ->orderByDesc('created_at')
            ->get();

        $canManage = $user && $user->can('manage_announcements');

        if ($user) {
            $announcements->each(function (Announcement $announcement) use ($user): void {
                $this->archiveService->hideUnviewableRelation($user, $announcement);
            });
        }

        return Inertia::render('Announcements/Index', [
            'announcements' => $announcements,
            'canManage' => $canManage,
            'archives' => $user
                ? $this->archiveService->recentViewable($user, 50)
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $canManage = $user && $user->can('manage_announcements');
        if (! $canManage) {
            abort(403, 'Anda tidak memiliki hak akses untuk mempublikasikan pengumuman.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:10000',
            'target_scope' => 'required|in:all,gtk_only,role_specific',
            'target_roles' => [
                'array',
                Rule::requiredIf(fn (): bool => $request->input('target_scope') === 'role_specific'),
                Rule::prohibitedIf(fn (): bool => $request->input('target_scope') !== 'role_specific'),
            ],
            'target_roles.*' => ['string', Rule::in(['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator', 'guru', 'pegawai'])],
            'priority' => 'required|in:low,normal,urgent',
            'is_pinned' => 'sometimes|boolean',
            'publish_date' => 'required|date',
            'expires_at' => 'nullable|date|after_or_equal:publish_date',
            'archive_id' => 'nullable|uuid|exists:archives,id',
        ]);

        if (! empty($validated['archive_id'])) {
            $archive = Archive::findOrFail($validated['archive_id']);
            abort_unless(
                $this->archiveService->canView($user, $archive),
                403,
                'Anda tidak berhak menautkan arsip yang tidak dapat Anda akses.'
            );
        }

        Announcement::create([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'target_scope' => $validated['target_scope'],
            'target_roles' => $validated['target_roles'] ?? null,
            'priority' => $validated['priority'],
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
            'publish_date' => $validated['publish_date'],
            'expires_at' => $validated['expires_at'] ?? null,
            'author_user_id' => $user->id,
            'archive_id' => $validated['archive_id'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Pengumuman madrasah berhasil dipublikasikan.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        $canDelete = $user && $user->can('delete_announcements');
        if (! $canDelete) {
            abort(403);
        }

        $announcement->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Pengumuman dinonaktifkan.');
    }
}
