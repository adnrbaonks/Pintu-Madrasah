<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $events = CalendarEvent::query()
            ->with(['meeting:id,meeting_number,title,status', 'academicYear:id,year,semester'])
            ->orderBy('start_time')
            ->limit(200)
            ->get();

        $activeYear = AcademicYear::where('is_active', true)->first(['id', 'year', 'semester']);

        return Inertia::render('Calendar/Index', [
            'events' => $events,
            'activeYear' => $activeYear,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasAnyRole(['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'])) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'event_type' => 'required|in:agenda,academic,holiday,meeting,exam',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after_or_equal:start_time',
            'is_all_day' => 'sometimes|boolean',
            'color' => 'nullable|string|max:20',
        ]);

        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        CalendarEvent::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'event_type' => $validated['event_type'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'] ?? null,
            'is_all_day' => (bool) ($validated['is_all_day'] ?? false),
            'color' => $validated['color'] ?? '#4f46e5',
            'academic_year_id' => $activeYearId,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Agenda kalender madrasah berhasil ditambahkan.');
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasAnyRole(['admin', 'kepala_madrasah', 'kaur_tu'])) {
            abort(403);
        }

        $event->delete();

        return redirect()->back()->with('success', 'Agenda kalender dihapus.');
    }
}
