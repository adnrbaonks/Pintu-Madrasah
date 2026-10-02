<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GtkController extends Controller
{
    /**
     * Tampilkan daftar GTK (Guru dan Tenaga Kependidikan)
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = Staff::query()
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('full_name_with_titles', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->orderBy('is_active', 'desc')
            ->orderBy('full_name_with_titles', 'asc');

        $staff = $query->paginate(15)->withQueryString();

        return Inertia::render('Gtk/Index', [
            'staff' => $staff,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }
}
