<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Archive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $announcements = Announcement::query()
            ->with(['author:id,name,role', 'archive:id,archive_number,title'])
            ->where('is_active', true)
            ->whereDate('publish_date', '<=', now()->toDateString())
            ->where(function ($q): void {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('publish_date')
            ->orderByDesc('created_at')
            ->get();

        $canManage = in_array($user->role, ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'], true);

        return Inertia::render('Announcements/Index', [
            'announcements' => $announcements,
            'canManage' => $canManage,
            'archives' => Archive::orderByDesc('created_at')->limit(50)->get(['id', 'archive_number', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'], true)) {
            abort(403, 'Anda tidak memiliki hak akses untuk mempublikasikan pengumuman.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:10000',
            'target_scope' => 'required|in:all,gtk_only,role_specific',
            'target_roles' => 'nullable|array',
            'priority' => 'required|in:low,normal,urgent',
            'is_pinned' => 'sometimes|boolean',
            'publish_date' => 'required|date',
            'expires_at' => 'nullable|date|after_or_equal:publish_date',
            'archive_id' => 'nullable|uuid|exists:archives,id',
        ]);

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
        if (! in_array($user->role, ['admin', 'kepala_madrasah', 'kaur_tu'], true)) {
            abort(403);
        }

        $announcement->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Pengumuman dinonaktifkan.');
    }
}
